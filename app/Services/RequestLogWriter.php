<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Minimal request-lifecycle sink. Never pass request bodies, URLs or credentials. */
class RequestLogWriter
{
    public function write(string $requestId, string $eventType, array $data): bool
    {
        $event = ['id'=>(string) Str::uuid(), 'request_id'=>$requestId, 'event_type'=>$eventType, 'occurred_at'=>now('UTC')->toIso8601String(), 'data'=>json_encode($data, JSON_THROW_ON_ERROR)];
        try {
            $this->persist($event);
            return true;
        } catch (\Throwable $e) {
            return $this->spool($event);
        }
    }

    public function action(array $event): bool
    {
        try { $this->persist($event); return true; }
        catch (\Throwable $e) { return $this->spool($event); }
    }

    /** Replay uses the ORIGINAL event identity; receipt and projection commit together. */
    public function persist(array $event): void
    {
        $db = DB::connection('logging');
        $db->transaction(function () use ($db, $event) {
            $checksum = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
            if ($db->getSchemaBuilder()->hasTable('log_ingestion_receipts')) {
                // Claim before projecting: concurrent replays serialize on this unique identity.
                $claimed = $db->table('log_ingestion_receipts')->insertOrIgnore(['event_id'=>$event['id'],'checksum'=>$checksum,'received_at'=>now()]);
                if (!$claimed) {
                    $receipt = $db->table('log_ingestion_receipts')->where('event_id',$event['id'])->first();
                    if (!$receipt || !hash_equals($receipt->checksum,$checksum)) throw new \DomainException('Conflicting log event.');
                    return;
                }
            }
            if (($event['record_type'] ?? 'request') === 'action') {
                $data = $event['context'];
                $db->table('audit_logs')->insertOrIgnore([
                    'id' => $event['id'], 'organization_id' => $data['organization_id'] ?? null, 'user_id' => $data['user_id'] ?? null,
                    'membership_id' => $data['membership_id'] ?? null, 'request_id' => $data['request_id'] ?? null, 'origin_request_id' => $data['origin_request_id'] ?? null,
                    'source' => $event['source'], 'action_name' => $event['action_name'], 'outcome' => $event['outcome'],
                    'occurred_at' => \Carbon\CarbonImmutable::parse($event['occurred_at'])->utc(), 'context' => json_encode($data, JSON_THROW_ON_ERROR),
                ]);
            } else {
                $db->table('request_log_events')->insertOrIgnore($event);
                // The legacy event table is retained for upgrade compatibility.
                if ($db->getSchemaBuilder()->hasTable('http_request_logs')) {
                    $data = json_decode($event['data'], true, flags: JSON_THROW_ON_ERROR);
                    $row = $db->table('http_request_logs')->where('request_id', $event['request_id'])->lockForUpdate()->first();
                    $context = array_replace($row ? json_decode($row->context, true) : [], $data);
                    $terminal = in_array($event['event_type'], ['completed', 'failed'], true);
                    $values = ['state' => $terminal ? $event['event_type'] : ($row->state ?? 'started'), 'context' => json_encode($context, JSON_THROW_ON_ERROR)];
                    if ($event['event_type'] === 'started') $values['started_at'] = \Carbon\CarbonImmutable::parse($event['occurred_at'])->utc();
                    if ($terminal) $values['completed_at'] = \Carbon\CarbonImmutable::parse($event['occurred_at'])->utc();
                    foreach (['organization_id', 'user_id', 'membership_id', 'method', 'route', 'duration_ms'] as $key) if (isset($context[$key])) $values[$key] = $context[$key];
                    if (isset($context['status'])) $values['http_status'] = $context['status'];
                    if ($row) $db->table('http_request_logs')->where('request_id', $event['request_id'])->update($values);
                    else $db->table('http_request_logs')->insert(['request_id' => $event['request_id']] + $values);
                }
            }
        });
    }

    private function spool(array $event): bool
    {
        try {
            $path = config('logging.request_spool_path');
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) { return false; }
            $line = Crypt::encryptString(json_encode($event, JSON_THROW_ON_ERROR))."\n";
            $file = @fopen($path, 'c+b');
            if (!$file) { return false; }
            @chmod($path, 0600);
            if (!flock($file, LOCK_EX)) { fclose($file); return false; }
            $stat = fstat($file);
            if (!$stat || $stat['size'] + strlen($line) > config('logging.request_spool_max_bytes')) { flock($file,LOCK_UN); fclose($file); return false; }
            fseek($file, 0, SEEK_END);
            $bytes = fwrite($file, $line);
            $ok = $bytes === strlen($line) && fflush($file) && (!function_exists('fsync') || fsync($file));
            if (!$ok) { ftruncate($file, $stat['size']); fflush($file); }
            flock($file, LOCK_UN); fclose($file);
            return $ok;
        } catch (\Throwable $e) { return false; }
    }
}
