<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Alerts\EvaluateVehicleCompliance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComplianceEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private function fleet(string $name, string $timezone = 'Asia/Kuala_Lumpur'): array
    {
        $org = Organization::create(['name' => $name, 'slug' => strtolower($name), 'timezone' => $timezone]);
        $user = User::create(['email_normalized' => strtolower($name).'@example.test', 'display_name' => $name]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $vehicle = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $vehicle, 'organization_id' => $org->id, 'registration_number' => 'TEST123', 'registration_normalized' => 'TEST123', 'registration_country' => 'MY', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return [$org, $user, $vehicle];
    }

    private function obligation(Organization $org, string $vehicle, string $code, ?array $record = null): string
    {
        $type = (string) Str::uuid(); $id = (string) Str::uuid();
        DB::table('compliance_types')->insert(['id' => $type, 'organization_id' => $org->id, 'code' => $code, 'name' => $code, 'subject_type' => 'vehicle', 'warning_days' => 30, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('compliance_obligations')->insert(['id' => $id, 'organization_id' => $org->id, 'compliance_type_id' => $type, 'subject_type' => 'vehicle', 'vehicle_id' => $vehicle, 'applicability' => 'applicable', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if ($record !== null) $this->record($org, $id, $record);
        return $id;
    }

    private function record(Organization $org, string $obligation, array $attributes): string
    {
        $id = (string) Str::uuid();
        DB::table('compliance_records')->insert(array_merge(['id' => $id, 'organization_id' => $org->id, 'obligation_id' => $obligation, 'cycle_number' => 1, 'valid_from' => '2026-01-01', 'expires_on' => '2026-11-16', 'state' => 'verified', 'idempotency_hash' => hash('sha256', $id), 'payload_hash' => hash('sha256', $id.'payload'), 'created_at' => now(), 'updated_at' => now()], $attributes));
        return $id;
    }

    public function test_local_expiry_boundary_creates_deduplicated_warning_then_critical(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T15:59:00Z'));
        [$org, $owner, $vehicle] = $this->fleet('Fleet');
        $obligation = $this->obligation($org, $vehicle, 'insurance', []);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseCount('alert_occurrences', 1);
        $this->assertDatabaseHas('alert_occurrences', ['organization_id' => $org->id, 'rule_key' => 'compliance:'.$obligation, 'threshold_key' => 'warning:30', 'severity' => 'warning']);
        $this->assertDatabaseHas('notification_records', ['organization_id' => $org->id, 'user_id' => $owner->id, 'kind' => 'compliance.threshold_crossed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16T16:00:00Z'));
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseCount('alert_occurrences', 2);
        $this->assertDatabaseHas('alert_occurrences', ['organization_id' => $org->id, 'rule_key' => 'compliance:'.$obligation, 'threshold_key' => 'expired', 'severity' => 'critical']);
        $this->assertDatabaseHas('alert_occurrences', ['organization_id' => $org->id, 'rule_key' => 'compliance:'.$obligation, 'threshold_key' => 'warning:30', 'state' => 'superseded']);
        $this->assertDatabaseHas('compliance_alert_transitions', ['organization_id' => $org->id, 'from_state' => 'open', 'to_state' => 'superseded', 'reason' => 'escalated_to_expired']);
        $this->assertDatabaseCount('compliance_alert_transitions', 1);
    }

    public function test_missing_future_unverified_suspended_and_foreign_organizations_are_isolated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T04:00:00Z'));
        [$first, $owner, $firstVehicle] = $this->fleet('First');
        [$second, $otherOwner, $secondVehicle] = $this->fleet('Second');
        $this->obligation($first, $firstVehicle, 'missing');
        $this->obligation($first, $firstVehicle, 'future', ['valid_from' => '2026-11-20', 'expires_on' => '2027-11-20']);
        $this->obligation($first, $firstVehicle, 'pending', ['state' => 'pending']);
        $this->obligation($first, $firstVehicle, 'suspended', ['suspended_at' => now()->subMinute()]);
        $this->obligation($second, $secondVehicle, 'active', []);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseCount('alert_occurrences', 1);
        $this->assertDatabaseCount('notification_records', 1);
        $this->assertDatabaseHas('notification_records', ['organization_id' => $second->id, 'user_id' => $otherOwner->id]);
        $this->assertDatabaseMissing('notification_records', ['organization_id' => $first->id, 'user_id' => $owner->id]);
    }

    public function test_bounded_batches_advance_and_wrap_without_starving_obligations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T04:00:00Z'));
        [$org, , $vehicle] = $this->fleet('Fleet');
        foreach (['tax', 'insurance', 'permit'] as $code) $this->obligation($org, $vehicle, $code, []);
        $evaluator = app(EvaluateVehicleCompliance::class);
        $this->assertSame(['scanned' => 1, 'created' => 1], $evaluator->run(1));
        $this->assertSame(['scanned' => 1, 'created' => 1], $evaluator->run(1));
        $this->assertSame(['scanned' => 1, 'created' => 1], $evaluator->run(1));
        $this->assertSame(['scanned' => 0, 'created' => 0], $evaluator->run(1));
        $this->assertSame(['scanned' => 1, 'created' => 0], $evaluator->run(1));
        $this->assertDatabaseCount('alert_occurrences', 3);
        $this->artisan('compliance:evaluate', ['--limit' => 0])->assertFailed();
    }

    public function test_daylight_saving_does_not_change_calendar_day_thresholds(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-07T17:00:00Z'));
        [$org, , $vehicle] = $this->fleet('DST', 'America/New_York');
        $obligation = $this->obligation($org, $vehicle, 'permit', ['expires_on' => '2026-03-09']);
        $type = DB::table('compliance_obligations')->where('id', $obligation)->value('compliance_type_id');
        DB::table('compliance_types')->where('id', $type)->update(['warning_days' => 1]);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseCount('alert_occurrences', 0);
        $this->travelTo(CarbonImmutable::parse('2026-03-08T16:00:00Z'));
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['rule_key' => 'compliance:'.$obligation, 'threshold_key' => 'warning:1', 'severity' => 'warning']);
    }

    public function test_effective_renewal_and_suspension_supersede_previous_cycle_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T04:00:00Z'));
        [$org, , $vehicle] = $this->fleet('Fleet');
        $obligation = $this->obligation($org, $vehicle, 'permit', []);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $old = DB::table('alert_occurrences')->where('organization_id', $org->id)->first();
        // A renewal beginning on the 18th leaves a real gap on the 17th.
        $newRecord = $this->record($org, $obligation, ['cycle_number' => 2, 'valid_from' => '2026-11-18', 'expires_on' => '2027-11-18']);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['id' => $old->id, 'state' => 'open']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16T16:00:00Z'));
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['organization_id' => $org->id, 'threshold_key' => 'expired', 'state' => 'open']);
        $this->travelTo(CarbonImmutable::parse('2026-11-17T16:00:00Z'));
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['id' => $old->id, 'state' => 'superseded']);
        $this->assertDatabaseHas('compliance_alert_transitions', ['alert_occurrence_id' => $old->id, 'reason' => 'escalated_to_expired']);
        $this->assertDatabaseHas('compliance_alert_transitions', ['reason' => 'coverage_replaced']);
        $this->assertDatabaseCount('compliance_alert_transitions', 2);
        $this->assertDatabaseCount('alert_occurrences', 2);
        DB::table('compliance_records')->where('id', $newRecord)->update(['suspended_at' => now()]);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseCount('alert_occurrences', 2);
    }

    public function test_continuous_verified_renewal_suppresses_warning_and_revocation_reopens_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T04:00:00Z'));
        [$org, $owner, $vehicle] = $this->fleet('Fleet');
        $obligation = $this->obligation($org, $vehicle, 'permit', []);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $old = DB::table('alert_occurrences')->where('organization_id', $org->id)->first();
        $oldNotification = DB::table('notification_records')->where('alert_occurrence_id', $old->id)->where('user_id', $owner->id)->first();
        DB::table('notification_records')->where('id', $oldNotification->id)->update(['read_at' => now()]);
        $future = $this->record($org, $obligation, ['cycle_number' => 2, 'valid_from' => '2026-11-17', 'expires_on' => '2027-11-17']);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['id' => $old->id, 'state' => 'superseded']);
        $this->assertDatabaseHas('compliance_alert_transitions', ['alert_occurrence_id' => $old->id, 'reason' => 'continuous_renewal_verified']);
        $this->assertDatabaseCount('alert_occurrences', 1);
        $this->assertNotNull(DB::table('notification_records')->where('id', $oldNotification->id)->value('read_at'));
        DB::table('compliance_records')->where('id', $future)->update(['suspended_at' => now()]);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['organization_id' => $org->id, 'threshold_key' => 'warning:30:reopened:1', 'state' => 'open']);
        $this->assertDatabaseCount('alert_occurrences', 2);
        $this->assertDatabaseCount('notification_records', 2);
        $this->assertNotNull(DB::table('notification_records')->where('id', $oldNotification->id)->value('read_at'));
        $replacement = $this->record($org, $obligation, ['cycle_number' => 3, 'valid_from' => '2026-11-17', 'expires_on' => '2028-11-17']);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['organization_id' => $org->id, 'threshold_key' => 'warning:30:reopened:1', 'state' => 'superseded']);
        DB::table('compliance_records')->where('id', $replacement)->update(['suspended_at' => now()]);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['organization_id' => $org->id, 'threshold_key' => 'warning:30:reopened:2', 'state' => 'open']);
        $this->assertDatabaseCount('notification_records', 3);
    }

    public function test_future_pending_or_gapped_renewal_does_not_suppress_warning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T04:00:00Z'));
        [$org, , $vehicle] = $this->fleet('Fleet');
        $gapped = $this->obligation($org, $vehicle, 'insurance', []);
        $pending = $this->obligation($org, $vehicle, 'permit', []);
        $this->record($org, $gapped, ['cycle_number' => 2, 'valid_from' => '2026-11-18', 'expires_on' => '2027-11-18']);
        $this->record($org, $pending, ['cycle_number' => 2, 'valid_from' => '2026-11-17', 'expires_on' => '2027-11-17', 'state' => 'pending']);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['rule_key' => 'compliance:'.$gapped, 'threshold_key' => 'warning:30', 'state' => 'open']);
        $this->assertDatabaseHas('alert_occurrences', ['rule_key' => 'compliance:'.$pending, 'threshold_key' => 'warning:30', 'state' => 'open']);
        $this->assertDatabaseCount('compliance_alert_transitions', 0);
    }

    public function test_only_a_verified_extending_continuous_future_record_suppresses_initial_warning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T04:00:00Z'));
        [$org, , $vehicle] = $this->fleet('Fleet');
        $continuous = $this->obligation($org, $vehicle, 'continuous', ['expires_on' => '2026-11-20']);
        $shorter = $this->obligation($org, $vehicle, 'shorter', ['expires_on' => '2026-11-20']);
        $suspended = $this->obligation($org, $vehicle, 'suspended', ['expires_on' => '2026-11-20']);
        $this->record($org, $continuous, ['cycle_number' => 2, 'valid_from' => '2026-11-18', 'expires_on' => '2027-11-18']);
        $this->record($org, $shorter, ['cycle_number' => 2, 'valid_from' => '2026-11-17', 'expires_on' => '2026-11-19']);
        $this->record($org, $suspended, ['cycle_number' => 2, 'valid_from' => '2026-11-17', 'expires_on' => '2027-11-17', 'suspended_at' => now()]);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseMissing('alert_occurrences', ['rule_key' => 'compliance:'.$continuous]);
        $this->assertDatabaseHas('alert_occurrences', ['rule_key' => 'compliance:'.$shorter, 'threshold_key' => 'warning:30', 'state' => 'open']);
        $this->assertDatabaseHas('alert_occurrences', ['rule_key' => 'compliance:'.$suspended, 'threshold_key' => 'warning:30', 'state' => 'open']);
    }

    public function test_suspending_current_record_supersedes_warning_without_new_alert(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T04:00:00Z'));
        [$org, , $vehicle] = $this->fleet('Fleet');
        $obligation = $this->obligation($org, $vehicle, 'permit', []);
        $record = DB::table('compliance_records')->where('obligation_id', $obligation)->first();
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $alert = DB::table('alert_occurrences')->where('organization_id', $org->id)->first();
        DB::table('compliance_records')->where('id', $record->id)->update(['suspended_at' => now()]);
        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences', ['id' => $alert->id, 'state' => 'superseded']);
        $this->assertDatabaseHas('compliance_alert_transitions', ['alert_occurrence_id' => $alert->id, 'reason' => 'coverage_not_active']);
        $this->assertDatabaseCount('alert_occurrences', 1);
    }
}
