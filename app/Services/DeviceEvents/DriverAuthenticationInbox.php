<?php

namespace App\Services\DeviceEvents;

use App\Services\DriverShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Accepts messages only from a broker queue protected by verified per-device ACLs. */
class DriverAuthenticationInbox
{
    public function __construct(private readonly DriverShiftService $shifts) {}

    public function receive(string $route, string $body): string
    {
        if (!config('broker.verified_topic_acls')) throw new \LogicException('Broker ACL verification is required.');
        $key = config('nfc.hmac_key');
        if (!is_string($key) || strlen($key) < 32) throw new \LogicException('NFC HMAC key is not configured.');
        $routeHash = hash('sha256', $route); $bodyHmac = hash_hmac('sha256', $body, $key);
        if (strlen($body) > 65536) return $this->quarantine($routeHash, $bodyHmac, 'too_large');
        if (!preg_match('/^fleet\.v1\.([0-9a-f-]{36})\.([0-9a-f-]{36})\.up\.events$/D', $route, $match) || !Str::isUuid($match[1]) || !Str::isUuid($match[2])) return $this->quarantine($routeHash, $bodyHmac, 'invalid_route');
        try { $msg = json_decode($body, true, 12, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { return $this->quarantine($routeHash, $bodyHmac, 'invalid_json'); }
        // The events topic carries types that this pilot does not implement.
        // Never acknowledge or discard another subsystem's message.
        if (is_array($msg) && is_string($msg['message_type'] ?? null) && $msg['message_type'] !== 'driver_authentication') return 'unsupported_message_type';
        if (!is_array($msg) || array_is_list($msg) || ($msg['schema_version'] ?? null) !== '1.0' || ($msg['message_type'] ?? null) !== 'driver_authentication'
            || ($msg['device_id'] ?? null) !== $match[2] || (isset($msg['organization_id']) && $msg['organization_id'] !== $match[1])
            || !is_string($msg['event_id'] ?? null) || !Str::isUuid($msg['event_id']) || !is_string($msg['installation_id'] ?? null) || !Str::isUuid($msg['installation_id'])
            || !is_string($msg['boot_id'] ?? null) || !Str::isUuid($msg['boot_id']) || !is_int($msg['sequence'] ?? null) || $msg['sequence'] < 0
            || !is_int($msg['uptime_ms'] ?? null) || $msg['uptime_ms'] < 0 || !is_array($msg['payload'] ?? null)
            || !is_string($msg['payload']['local_session_id'] ?? null) || !Str::isUuid($msg['payload']['local_session_id'])
            || ($msg['payload']['credential_type'] ?? null) !== 'nfc_uid_sha256'
            || !is_string($msg['payload']['credential_fingerprint'] ?? null) || !preg_match('/^[0-9a-f]{64}$/iD', $msg['payload']['credential_fingerprint'])
            || !is_string($msg['occurred_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $msg['occurred_at'])) return $this->quarantine($routeHash, $bodyHmac, 'invalid_envelope');
        // Offline authorization must not be treated as an online decision: its
        // signed snapshots and reconciliation are not implemented in this pilot.
        if (($msg['payload']['offline_authorization_version'] ?? null) !== null) return 'unsupported_offline_auth';
        try { $occurred = CarbonImmutable::parse($msg['occurred_at'])->utc(); }
        catch (\Throwable $e) { return $this->quarantine($routeHash, $bodyHmac, 'invalid_timestamp'); }
        if ($occurred->greaterThan(now()->addMinutes(5))) return $this->quarantine($routeHash, $bodyHmac, 'future_timestamp');

        return DB::transaction(function () use ($msg, $match, $bodyHmac, $routeHash, $occurred) {
            $org = DB::table('organizations')->where('id', $match[1])->where('status', 'active')->lockForUpdate()->first();
            $device = DB::table('devices')->where('organization_id', $match[1])->where('id', $match[2])->lockForUpdate()->first();
            if (!$org || !$device || $device->status !== 'active' || !$device->broker_principal) return $this->quarantine($routeHash, $bodyHmac, 'unprovisioned_device');
            if (!app(\App\Services\Entitlements::class)->active($match[1])) return $this->quarantine($routeHash,$bodyHmac,'subscription_inactive');
            $prior = DB::table('device_event_receipts')->where('device_id', $device->id)->where('event_id', $msg['event_id'])->first();
            if ($prior) {
                if (!hash_equals($prior->payload_hmac, $bodyHmac)) return $this->quarantine($routeHash, $bodyHmac, 'conflicting_event');
                // A device can retry after losing a previously published downstream message.
                DB::table('device_event_outbox')->where('receipt_id', $prior->id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->update(['published_at' => null, 'available_at' => now(), 'generation'=>DB::raw('generation + 1')]);
                return 'duplicate';
            }
            if (DB::table('device_event_receipts')->where('device_id', $device->id)->where('boot_id', $msg['boot_id'])->where('sequence', $msg['sequence'])->exists()
                || DB::table('telemetry_inbox')->where('device_id', $device->id)->where(fn ($q) => $q->where('event_id', $msg['event_id'])->orWhere(fn ($q) => $q->where('boot_id', $msg['boot_id'])->where('sequence', $msg['sequence'])))->exists()) return $this->quarantine($routeHash, $bodyHmac, 'conflicting_sequence_or_event');
            $installation = DB::table('device_installations')->where('organization_id', $match[1])->where('device_id', $device->id)->where('id', $msg['installation_id'])->where('effective_from', '<=', $occurred)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $occurred))->first();
            if (!$installation) return $this->quarantine($routeHash, $bodyHmac, 'invalid_installation');
            $decision = $this->shifts->acceptAuthenticatedEvent($match[1], $device->id, [
                'event_id' => $msg['event_id'], 'installation_id' => $msg['installation_id'], 'occurred_at' => $msg['occurred_at'],
                'local_session_id' => $msg['payload']['local_session_id'], 'credential_type' => $msg['payload']['credential_type'], 'credential_fingerprint' => $msg['payload']['credential_fingerprint'],
            ]);
            $receipt = (string) Str::uuid(); $now = now();
            DB::table('device_event_receipts')->insert(['id'=>$receipt, 'organization_id'=>$match[1], 'device_id'=>$device->id, 'event_id'=>$msg['event_id'], 'installation_id'=>$installation->id, 'boot_id'=>$msg['boot_id'], 'sequence'=>$msg['sequence'], 'payload_hmac'=>$bodyHmac, 'decision'=>$decision['decision'], 'shift_id'=>$decision['shift_id'], 'occurred_at'=>$occurred, 'received_at'=>$now]);
            $resultId = (string) Str::uuid(); $expires = $now->copy()->addMinutes(5);
            $result = ['schema_version'=>'1.0', 'message_type'=>'driver_authentication_result', 'correlation_id'=>$msg['event_id'], 'result'=>$decision['decision'], 'shift_id'=>$decision['shift_id'], 'local_session_id'=>$msg['payload']['local_session_id'], 'message_id'=>$resultId, 'device_id'=>$device->id, 'installation_id'=>$installation->id, 'issued_at'=>$now->toIso8601String(), 'expires_at'=>$expires->toIso8601String()];
            if ($decision['shift_id']) {
                $shift = DB::table('driver_shifts')->join('drivers','driver_shifts.driver_id','=','drivers.id')->where('driver_shifts.organization_id',$match[1])->where('driver_shifts.id',$decision['shift_id'])->select('drivers.name','driver_shifts.attribution_expires_at')->first();
                if ($shift) { $result['driver_display_name'] = $shift->name; $result['valid_until'] = CarbonImmutable::parse($shift->attribution_expires_at)->utc()->toIso8601String(); }
            }
            $ack = ['schema_version'=>'1.0', 'message_type'=>'ingest_ack', 'device_id'=>$device->id, 'acknowledged_at'=>$now->toIso8601String(), 'results'=>[['event_id'=>$msg['event_id'], 'status'=>'accepted']]];
            $base = 'fleet.v1.'.$match[1].'.'.$device->id.'.down.';
            foreach ([[$resultId,'result','results',$result,$expires], [(string) Str::uuid(),'ingest_ack','ingest_ack',$ack,null]] as [$id,$kind,$suffix,$payload,$expiry]) {
                DB::table('device_event_outbox')->insert(['id'=>$id,'receipt_id'=>$receipt,'kind'=>$kind,'routing_key'=>$base.$suffix,'payload'=>json_encode($payload, JSON_THROW_ON_ERROR),'expires_at'=>$expiry,'attempts'=>0,'available_at'=>$now,'created_at'=>$now]);
            }
            return 'accepted';
        });
    }

    private function quarantine(string $route, string $body, string $reason): string
    {
        DB::table('device_event_quarantine')->insert(['id'=>(string) Str::uuid(), 'route_sha256'=>$route, 'payload_hmac'=>$body, 'reason'=>$reason, 'received_at'=>now()]);
        return 'quarantined';
    }
}
