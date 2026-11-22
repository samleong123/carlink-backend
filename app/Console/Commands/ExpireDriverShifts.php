<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireDriverShifts extends Command
{
    protected $signature = 'driver-shifts:expire';
    protected $description = 'Close shifts whose attribution period has expired';

    public function handle(): int
    {
        DB::table('driver_shifts')->where('state', 'active')->where('attribution_expires_at', '<=', now())->orderBy('id')->select('id', 'organization_id')->chunkById(100, function ($items) {
            foreach ($items as $candidate) {
                DB::transaction(function () use ($candidate) {
                    DB::table('organizations')->where('id', $candidate->organization_id)->lockForUpdate()->first();
                    $row = DB::table('driver_shifts')->where('id', $candidate->id)->where('state', 'active')->where('attribution_expires_at', '<=', now())->first();
                    if (!$row) return;
                    DB::table('driver_shifts')->where('id', $row->id)->update(['state' => 'closed', 'ended_at' => $row->attribution_expires_at, 'closure_reason' => 'attribution_expired', 'version' => $row->version + 1, 'updated_at' => now()]);
                });
            }
        });
        return self::SUCCESS;
    }
}
