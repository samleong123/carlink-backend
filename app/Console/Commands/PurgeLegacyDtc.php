<?php
namespace App\Console\Commands;
use App\Services\DtcPrivacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeLegacyDtc extends Command
{
    protected $signature='privacy:purge-legacy-dtc {--acknowledge}';
    protected $description='Remove diagnostic codes from legacy telemetry payloads and projections; review backups separately';
    public function handle(): int
    {
        if (!$this->option('acknowledge')) { $this->error('Review a backup and supply --acknowledge. This removes stored fault codes, not telemetry measurements.'); return self::FAILURE; }
        foreach (['telemetry_inbox'=>'raw_payload','telemetry_samples'=>'vehicle_data'] as $table=>$column) {
            DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(100,function($rows) use ($table,$column) {
                foreach ($rows as $row) DB::table($table)->where('id',$row->id)->update([$column=>json_encode(app(DtcPrivacy::class)->scrub(json_decode($row->$column,false,32,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR)]);
            });
        }
        $this->info('Legacy application DTC payloads scrubbed. Review database backups, broker queues, recordings and external provider retention separately.'); return self::SUCCESS;
    }
}
