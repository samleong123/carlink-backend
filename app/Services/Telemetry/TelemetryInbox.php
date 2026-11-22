<?php

namespace App\Services\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * Stores telemetry and durable ACK/projection responsibility. Identity comes from a broker-authorized route,
 * never from an MQTT user property or a payload organization field.
 */
class TelemetryInbox
{
    public function receive(string $routingKey, string $body): string
    {
        if (!config('broker.verified_topic_acls')) {
            throw new \LogicException('Broker topic ACL verification is not configured.');
        }
        $routeHash = hash('sha256', $routingKey);
        $hash = hash('sha256', $body);
        if (strlen($body) > 65536) return $this->quarantine($routeHash, $hash, 'too_large');
        if (!preg_match('/^fleet\.v1\.([0-9a-f-]{36})\.([0-9a-f-]{36})\.up\.telemetry$/D', $routingKey, $matches)
            || !Str::isUuid($matches[1]) || !Str::isUuid($matches[2])) {
            return $this->quarantine($routeHash, $hash, 'invalid_route');
        }
        try {
            $object = json_decode($body, false, 12, JSON_THROW_ON_ERROR);
            $message = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->quarantine($routeHash, $hash, 'invalid_json');
        }
        $pi = is_object($object) && isset($object->gateway) && !isset($object->schema_version);
        if ($pi) {
            if ($reason = app(PiTelemetryValidator::class)->reason($message, $object)) return $this->quarantine($routeHash, $hash, $reason);
            // Internal acceptance metadata only; raw wire JSON remains unchanged.
            $message['occurred_at'] = $message['timestamp']; $message['sent_at'] = $message['timestamp'];
            $message['boot_id'] = null; $message['sequence'] = null; $message['installation_id'] = null;
        }
        if (!$pi && (!is_array($message) || array_is_list($message)
            || ($message['schema_version'] ?? null) !== '1.0'
            || ($message['message_type'] ?? null) !== 'telemetry'
            || !is_string($message['device_id'] ?? null) || $message['device_id'] !== $matches[2]
            || !is_string($message['event_id'] ?? null) || !Str::isUuid($message['event_id'])
            || !is_string($message['installation_id'] ?? null) || !Str::isUuid($message['installation_id'])
            || !is_string($message['boot_id'] ?? null) || !Str::isUuid($message['boot_id'])
            || !is_int($message['sequence'] ?? null) || $message['sequence'] < 0
            || !is_int($message['uptime_ms'] ?? null) || $message['uptime_ms'] < 0
            || !is_string($message['occurred_at'] ?? null)
             || !is_object($object->payload ?? null) || !is_object($object->clock ?? null)
             || (isset($object->payload->vehicle) && !is_object($object->payload->vehicle))
            || !is_string($message['sent_at'] ?? null) || !is_int($message['config_version'] ?? null) || $message['config_version'] < 0
            || !is_string($message['edge_version'] ?? null) || $message['edge_version'] === '' || strlen($message['edge_version']) > 100
             || (isset($message['organization_id']) && $message['organization_id'] !== $matches[1]))) {
            return $this->quarantine($routeHash, $hash, 'invalid_envelope');
        }
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $message['occurred_at'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $message['sent_at'])) {
                throw new \InvalidArgumentException('Invalid timestamp');
            }
            $captured = CarbonImmutable::parse($message['occurred_at']);
            $sent = CarbonImmutable::parse($message['sent_at']);
            if ($captured->format('Y-m-d\TH:i:s') !== substr($message['occurred_at'],0,19) || $sent->format('Y-m-d\TH:i:s') !== substr($message['sent_at'],0,19)) throw new \InvalidArgumentException('Invalid calendar date');
            $occurredAt = $captured->utc();
        } catch (\Throwable $e) {
            return $this->quarantine($routeHash, $hash, 'invalid_timestamp');
        }
        if ($occurredAt->greaterThan(now()->addMinutes(5))) return $this->quarantine($routeHash, $hash, 'future_timestamp');
        if ($occurredAt->lessThan(now()->subDays(7))) return $this->quarantine($routeHash, $hash, 'outside_replay_horizon');
        if (!$pi && ($reason = app(TelemetryValidator::class)->reason($message))) return $this->quarantine($routeHash, $hash, $reason);

        return DB::transaction(function () use ($matches, $message, $occurredAt, $hash, $routeHash, $body, $pi) {
            $org = DB::table('organizations')->where('id',$matches[1])->where('status','active')->lockForUpdate()->first();
            if (!$org) return $this->quarantine($routeHash,$hash,'inactive_organization');
            if (!app(\App\Services\Entitlements::class)->active($matches[1])) return $this->quarantine($routeHash,$hash,'subscription_inactive');
            // Serialize inbox decisions per device so a duplicate cannot race the uniqueness checks.
            $device = DB::table('devices')->where('organization_id', $matches[1])->where('id', $matches[2])->lockForUpdate()->first();
            if (!$device || $device->status !== 'active' || !$device->broker_principal) {
                return $this->quarantine($routeHash, $hash, 'unprovisioned_device');
            }
            if ($pi && !hash_equals($device->serial_number, $message['gateway']['device_id'])) return $this->quarantine($routeHash, $hash, 'device_serial_mismatch');
            if (DB::table('device_event_receipts')->where('device_id', $device->id)->where(fn ($q) => $q->where('event_id', $message['event_id'])->when(!$pi, fn ($q) => $q->orWhere(fn ($q) => $q->where('boot_id', $message['boot_id'])->where('sequence', $message['sequence']))))->exists()) {
                return $this->quarantine($routeHash, $hash, 'conflicting_event_or_sequence');
            }
            $existing = DB::table('telemetry_inbox')->where('device_id', $device->id)->where('event_id', $message['event_id'])->first();
            if ($existing) {
                if (!hash_equals($existing->payload_sha256, $hash)) return $this->quarantine($routeHash, $hash, 'conflicting_event');
                app(\App\Services\Broker\BrokerOutbox::class)->ingestAck($existing, 'duplicate');
                return 'duplicate';
            }
            if (!$pi && DB::table('telemetry_inbox')->where('device_id', $device->id)->where('boot_id', $message['boot_id'])->where('sequence', $message['sequence'])->exists()) {
                return $this->quarantine($routeHash, $hash, 'conflicting_sequence');
            }
            $installation = DB::table('device_installations')->where('organization_id', $matches[1])->where('device_id', $matches[2])
                ->when(!$pi, fn ($q) => $q->where('id', $message['installation_id']))->where('effective_from', '<=', $occurredAt)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $occurredAt))->get();
            if ($installation->count() !== 1) return $this->quarantine($routeHash, $hash, 'invalid_installation');
            $installation = $installation->first();
            if (!$installation) return $this->quarantine($routeHash, $hash, 'invalid_installation');
            if ($pi) {
                $plate = DB::table('vehicles')->where('organization_id',$matches[1])->where('id',$installation->vehicle_id)->value('registration_normalized');
                if ($plate !== preg_replace('/[^A-Z0-9]/','',strtoupper($message['gateway']['vehicle_id']))) return $this->quarantine($routeHash, $hash, 'vehicle_plate_mismatch');
            }
            $id = (string) Str::uuid();
            if (config('saas.enforce_subscriptions')) {
                $settings=json_decode(DB::table('organization_settings')->where('organization_id',$matches[1])->value('settings') ?? '{}',true);
                $minimum=max($settings['telemetry_interval_seconds'] ?? 2,app(\App\Services\Entitlements::class)->plan($matches[1])['telemetry_min_seconds'] ?? 2);
                if (DB::table('telemetry_inbox')->where('device_id',$device->id)->where('occurred_at','>',$occurredAt->subSeconds($minimum))->where('occurred_at','<',$occurredAt->addSeconds($minimum))->exists()) return $this->quarantine($routeHash,$hash,'telemetry_too_frequent');
            }
            DB::table('telemetry_inbox')->insert([
                'id' => $id, 'organization_id' => $matches[1], 'device_id' => $matches[2],
                'installation_id' => $installation->id, 'event_id' => $message['event_id'], 'boot_id' => $message['boot_id'],
                'sequence' => $message['sequence'], 'occurred_at' => $occurredAt, 'received_at' => now(),
                'payload_sha256' => $hash, 'raw_payload' => json_encode(app(\App\Services\DtcPrivacy::class)->scrub(json_decode($body,false,12,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR), 'state' => 'pending_projection', 'source_format'=>$pi ? 'pi_snapshot_v1' : 'canonical_v1',
            ]);
            app(\App\Services\Broker\BrokerOutbox::class)->ingestAck(DB::table('telemetry_inbox')->where('id',$id)->first(), 'accepted');
            $audit = app(\App\Services\ActionAudit::class);
            $audit->outbox($audit->event('telemetry.accept', 'succeeded', 'mqtt', ['organization_id'=>$matches[1], 'device_id'=>$matches[2], 'source_event_id'=>$message['event_id'], 'browser_absence_reason'=>'non_browser_execution']));
            return 'stored';
        });
    }

    private function quarantine(string $routeHash, string $payloadHash, string $reason): string
    {
        DB::table('telemetry_quarantine')->insert([
            'id' => (string) Str::uuid(), 'route_sha256' => $routeHash, 'payload_sha256' => $payloadHash,
            'reason' => $reason, 'received_at' => now(),
        ]);
        return 'quarantined';
    }
}
