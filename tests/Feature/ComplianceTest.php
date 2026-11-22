<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function fleet(string $slug, string $role='owner'): array
    {
        $user=User::create(['email_normalized'=>$slug.'@example.test','display_name'=>$slug]);
        $org=Organization::create(['name'=>$slug,'slug'=>$slug]);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>$role,'status'=>'active']);
        $this->actingAs($user);
        return [$user,$org,'/api/v1/organizations/'.$org->id];
    }
    private function obligation(string $base): string
    {
        $vehicle=$this->postJson($base.'/vehicles',['registration_number'=>'ABC123','registration_country'=>'MY'])->assertCreated()->json('data.item.id');
        $type=$this->postJson($base.'/compliance-types',['code'=>'insurance','name'=>'Insurance','subject_type'=>'vehicle','warning_days'=>7])->assertCreated()->json('data.id');
        return $this->postJson($base.'/compliance-obligations',['compliance_type_id'=>$type,'subject_type'=>'vehicle','subject_id'=>$vehicle])->assertCreated()->json('data.id');
    }
    public function test_renewal_requires_verified_evidence_and_is_idempotent(): void
    {
        [,,$base]=$this->fleet('evidence'); $id=$this->obligation($base); $url=$base.'/compliance-obligations/'.$id.'/renewals';
        $body=['reference_number'=>'example','valid_from'=>'2026-01-01','expires_on'=>'2026-12-31'];
        $headers=['Idempotency-Key'=>'repeat-safe-1'];
        $record=$this->postJson($url,$body,$headers)->assertCreated()->json('data.id');
        $this->postJson($url,$body,$headers)->assertCreated()->assertJsonPath('data.id',$record);
        $this->postJson($url,array_merge($body,['reference_number'=>'changed']),$headers)->assertStatus(409);
        $this->postJson($base.'/compliance-records/'.$record.'/verify')->assertStatus(422);
        $this->getJson($base.'/compliance-obligations/'.$id)->assertJsonPath('data.status.status','unknown');
        $this->assertSame(1, DB::table('compliance_records')->count());
    }
    public function test_future_verified_renewal_and_real_gap_use_local_calendar_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-30T15:59:00Z'));
        [,,$base]=$this->fleet('calendar'); $id=$this->obligation($base); $url=$base.'/compliance-obligations/'.$id.'/renewals';
        $current=$this->postJson($url,['valid_from'=>'2026-01-01','expires_on'=>'2026-06-30','evidence_reference'=>'file-register-1'],['Idempotency-Key'=>'calendar-key-1'])->assertCreated()->json('data.id');
        $future=$this->postJson($url,['valid_from'=>'2026-07-02','expires_on'=>'2027-06-30','evidence_reference'=>'file-register-2'],['Idempotency-Key'=>'calendar-key-2'])->assertCreated()->json('data.id');
        $this->postJson($base.'/compliance-records/'.$current.'/verify')->assertOk();
        $this->postJson($base.'/compliance-records/'.$future.'/verify')->assertOk();
        $this->getJson($base.'/compliance-obligations/'.$id)->assertJsonPath('data.status.record_id',$current)->assertJsonPath('data.status.status','due_soon')->assertJsonPath('data.status.days_remaining',0);
        $this->travelTo(CarbonImmutable::parse('2026-06-30T16:01:00Z'));
        $this->getJson($base.'/compliance-obligations/'.$id)->assertJsonPath('data.status.record_id',$current)->assertJsonPath('data.status.status','expired');
        $this->travelTo(CarbonImmutable::parse('2026-07-01T16:01:00Z'));
        $this->getJson($base.'/compliance-obligations/'.$id)->assertJsonPath('data.status.record_id',$future)->assertJsonPath('data.status.status','valid');
    }
    public function test_suspension_and_other_current_verified_coverage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        [,,$base]=$this->fleet('suspension'); $id=$this->obligation($base); $url=$base.'/compliance-obligations/'.$id.'/renewals';
        $one=$this->postJson($url,['valid_from'=>'2026-01-01','expires_on'=>'2027-12-31','evidence_reference'=>'record-1'],['Idempotency-Key'=>'suspend-key-1'])->assertCreated()->json('data.id');
        $two=$this->postJson($url,['valid_from'=>'2026-01-01','expires_on'=>'2027-06-30','evidence_reference'=>'record-2'],['Idempotency-Key'=>'suspend-key-2'])->assertCreated()->json('data.id');
        $this->postJson($base.'/compliance-records/'.$one.'/verify')->assertOk(); $this->postJson($base.'/compliance-records/'.$two.'/verify')->assertOk();
        $this->postJson($base.'/compliance-records/'.$one.'/suspend',['reason'=>'Document withdrawn'])->assertOk();
        $this->getJson($base.'/compliance-obligations/'.$id)->assertJsonPath('data.status.status','valid')->assertJsonPath('data.status.record_id',$two);
        $this->postJson($base.'/compliance-records/'.$two.'/suspend',['reason'=>'Document withdrawn'])->assertOk();
        $this->getJson($base.'/compliance-obligations/'.$id)->assertJsonPath('data.status.status','suspended');
        $this->getJson($base.'/compliance-records/'.$one.'/history')->assertOk()->assertJsonCount(2,'data.records');
        $this->assertSame(2,DB::table('compliance_action_events')->where('event','record_suspended')->count());
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        $this->getJson($base.'/compliance-obligations/'.$id)->assertJsonPath('data.status.status','expired');
    }
    public function test_cross_tenant_records_and_driver_access_are_denied(): void
    {
        [, $first,$base]=$this->fleet('first'); $id=$this->obligation($base);
        [, $second,$other]=$this->fleet('second');
        $this->getJson($other.'/compliance-obligations/'.$id)->assertNotFound();
        $this->postJson($other.'/compliance-obligations/'.$id.'/renewals',['valid_from'=>'2026-01-01','expires_on'=>'2027-01-01'],['Idempotency-Key'=>'across-tenant'])->assertNotFound();
        [$driver,$driverOrg,$driverBase]=$this->fleet('driver','driver');
        $this->getJson($driverBase.'/compliance/status')->assertForbidden();
    }
}
