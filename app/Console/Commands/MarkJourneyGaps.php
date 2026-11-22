<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkJourneyGaps extends Command
{
    protected $signature = 'journeys:mark-gaps';
    protected $description = 'Mark active pilot journeys incomplete after one hour without an observation';

    public function handle(): int
    {
        $candidates = DB::table('journey_detection_states')->whereNotNull('current_journey_id')
            ->where('last_observed_at', '<=', now()->subMinutes(60))->get(['id', 'organization_id', 'vehicle_id']);
        foreach ($candidates as $candidate) {
            DB::transaction(function () use ($candidate) {
                $vehicle = DB::table('vehicles')->where('organization_id', $candidate->organization_id)->where('id', $candidate->vehicle_id)->lockForUpdate()->first();
                if (!$vehicle) return;
                $state = DB::table('journey_detection_states')->where('id', $candidate->id)->lockForUpdate()->first();
                if (!$state || !$state->current_journey_id || !$state->last_observed_at || \Carbon\CarbonImmutable::parse($state->last_observed_at)->gt(now()->subMinutes(60))) return;
                DB::table('journeys')->where('organization_id', $candidate->organization_id)->where('id', $state->current_journey_id)->where('state', 'active')->update([
                    'state' => 'incomplete', 'ended_at' => $state->last_observed_at, 'end_observation_id' => $state->last_observation_id,
                    'end_reason' => 'automatic_end', 'updated_at' => now(),
                ]);
                DB::table('journey_detection_states')->where('id', $state->id)->update([
                    'state' => 'stationary', 'current_journey_id' => null, 'rebuild_before' => now(), 'candidate_started_at' => null,
                    'candidate_observation_id' => null, 'stop_mode' => null, 'updated_at' => now(),
                ]);
            });
        }
        return self::SUCCESS;
    }
}
