<?php
namespace App\Console\Commands;
use App\Services\PrivateAssets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupPrivateAssets extends Command
{
    protected $signature='files:cleanup {--limit=100}';
    protected $description='Revoke stalled uploads and retry deletion of failed/revoked private S3 objects';
    public function handle(PrivateAssets $assets): int
    {
        $failed=0; $limit=max(1,min(1000,(int)$this->option('limit')));
        // Never races a normal bounded scanner/storage request. A process paused for >1 hour must reconcile.
        DB::table('private_assets')->where('state','uploading')->where('updated_at','<',now()->subHour())->update(['state'=>'failed','updated_at'=>now()]);
        foreach (DB::table('private_assets')->whereIn('state',['failed','revoked'])->whereNull('purged_at')->orderBy('id')->limit($limit)->get() as $row) if (!$assets->purge($row)) $failed++;
        if ($failed) { $this->error('Private object cleanup unavailable; retry after storage recovery.'); return self::FAILURE; }
        return self::SUCCESS;
    }
}
