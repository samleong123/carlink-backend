<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\DeviceEvents\DriverAuthenticationInbox;
use App\Services\Telemetry\TelemetryInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceEventPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['broker.verified_topic_acls'=>true, 'nfc.hmac_key'=>str_repeat('K', 40), 'nfc.hmac_key_version'=>'1']);
        $user=User::create(['email_normalized'=>'manager@example.test','display_name'=>'Manager']);
        $org=Organization::create(['name'=>'Fleet','slug'=>'fleet']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'owner','status'=>'active']);
        $driver=(string) Str::uuid(); $vehicle=(string) Str::uuid(); $device=(string) Str::uuid(); $installation=(string) Str::uuid();
        DB::table('drivers')->insert(['id'=>$driver,'organization_id'=>$org->id,'name'=>'Driver A','status'=>'active','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('vehicles')->insert(['id'=>$vehicle,'organization_id'=>$org->id,'registration_number'=>'ABC123','registration_normalized'=>'ABC123','registration_country'=>'MY','status'=>'active','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('devices')->insert(['id'=>$device,'organization_id'=>$org->id,'serial_number'=>'pi-one','status'=>'active','broker_principal'=>'pi-one','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('device_installations')->insert(['id'=>$installation,'organization_id'=>$org->id,'vehicle_id'=>$vehicle,'device_id'=>$device,'effective_from'=>now()->subDay(),'created_at'=>now(),'updated_at'=>now()]);
        $fingerprint=str_repeat('a',64);
        $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials',['credential_fingerprint'=>$fingerprint,'credential_type'=>'nfc_uid_sha256'])->assertCreated();
        $message=['schema_version'=>'1.0','message_type'=>'driver_authentication','event_id'=>(string) Str::uuid(),'device_id'=>$device,'installation_id'=>$installation,'boot_id'=>(string) Str::uuid(),'sequence'=>42,'occurred_at'=>now()->toIso8601String(),'uptime_ms'=>120000,'payload'=>['credential_type'=>'nfc_uid_sha256','credential_fingerprint'=>$fingerprint,'local_session_id'=>(string) Str::uuid()]];
        return [$org, $device, $message];
    }

    public function test_acceptance_and_retry_are_transactional_and_do_not_store_fingerprint(): void
    {
        [$org,$device,$msg]=$this->fixture(); $route='fleet.v1.'.$org->id.'.'.$device.'.up.events'; $json=json_encode($msg,JSON_THROW_ON_ERROR);
        $inbox=app(DriverAuthenticationInbox::class);
        $this->assertSame('accepted',$inbox->receive($route,$json));
        $this->assertDatabaseCount('device_event_receipts',1); $this->assertDatabaseCount('driver_shifts',1); $this->assertDatabaseCount('device_event_outbox',2);
        $result=DB::table('device_event_outbox')->where('kind','result')->first();
        $decoded=json_decode($result->payload,true,flags: JSON_THROW_ON_ERROR);
        $this->assertSame('accepted',$decoded['result']); $this->assertSame($msg['event_id'],$decoded['correlation_id']); $this->assertSame($result->id,$decoded['message_id']);
        $this->assertStringNotContainsString($msg['payload']['credential_fingerprint'], json_encode(DB::table('device_event_receipts')->get()->all()));
        $this->assertStringNotContainsString($msg['payload']['credential_fingerprint'], json_encode(DB::table('device_event_outbox')->get()->all()));
        DB::table('device_event_outbox')->update(['published_at'=>now()]);
        $this->assertSame('duplicate',$inbox->receive($route,$json));
        $this->assertSame(2,DB::table('device_event_outbox')->whereNull('published_at')->where('generation',2)->count());
        $this->assertDatabaseCount('driver_shifts',1); $this->assertDatabaseCount('device_event_receipts',1);
    }

    public function test_invalid_and_conflicting_events_do_not_authenticate(): void
    {
        [$org,$device,$msg]=$this->fixture(); $route='fleet.v1.'.$org->id.'.'.$device.'.up.events'; $inbox=app(DriverAuthenticationInbox::class);
        $this->assertSame('accepted',$inbox->receive($route,json_encode($msg,JSON_THROW_ON_ERROR)));
        $changed=$msg; $changed['payload']['credential_fingerprint']=str_repeat('b',64);
        $this->assertSame('quarantined',$inbox->receive($route,json_encode($changed,JSON_THROW_ON_ERROR)));
        $changed=$msg; $changed['event_id']=(string) Str::uuid();
        $this->assertSame('quarantined',$inbox->receive($route,json_encode($changed,JSON_THROW_ON_ERROR)));
        $changed=$msg; $changed['device_id']=(string) Str::uuid();
        $this->assertSame('quarantined',$inbox->receive($route,json_encode($changed,JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('driver_shifts',1); $this->assertDatabaseCount('device_event_quarantine',3);
    }

    public function test_other_event_types_are_not_discarded_and_acl_is_required(): void
    {
        [$org,$device,$msg]=$this->fixture(); $route='fleet.v1.'.$org->id.'.'.$device.'.up.events'; $inbox=app(DriverAuthenticationInbox::class);
        $msg['message_type']='diagnostic_scan';
        $this->assertSame('unsupported_message_type',$inbox->receive($route,json_encode($msg,JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('device_event_receipts',0); $this->assertDatabaseCount('device_event_quarantine',0);
        $msg['message_type']='driver_authentication'; $msg['payload']['offline_authorization_version']=1;
        $this->assertSame('unsupported_offline_auth',$inbox->receive($route,json_encode($msg,JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('driver_shifts',0);
        config(['broker.verified_topic_acls'=>false]);
        $this->expectException(\LogicException::class); $inbox->receive($route,json_encode($msg,JSON_THROW_ON_ERROR));
    }

    public function test_telemetry_cannot_reuse_a_device_event_identity(): void
    {
        [$org,$device,$msg]=$this->fixture(); $route='fleet.v1.'.$org->id.'.'.$device;
        $this->assertSame('accepted',app(DriverAuthenticationInbox::class)->receive($route.'.up.events',json_encode($msg,JSON_THROW_ON_ERROR)));
        $telemetry=$msg; $telemetry['message_type']='telemetry'; $telemetry['sent_at']=now()->toIso8601String(); $telemetry['config_version']=1; $telemetry['edge_version']='test'; $telemetry['clock']=['synchronized'=>true];
        $this->assertSame('quarantined',app(TelemetryInbox::class)->receive($route.'.up.telemetry',json_encode($telemetry,JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('telemetry_inbox',0);
    }

    public function test_replay_during_outbox_publication_remains_pending_for_redelivery(): void
    {
        [$org,$device,$msg]=$this->fixture(); $route='fleet.v1.'.$org->id.'.'.$device.'.up.events'; $json=json_encode($msg,JSON_THROW_ON_ERROR);
        $inbox=app(DriverAuthenticationInbox::class);
        $this->assertSame('accepted',$inbox->receive($route,$json));
        $command=app(\App\Console\Commands\PublishDeviceEventOutbox::class);
        $claim=new \ReflectionMethod($command,'claim'); $finish=new \ReflectionMethod($command,'finish');
        $claimed=$claim->invoke($command); $this->assertNotNull($claimed);
        $this->assertSame('duplicate',$inbox->receive($route,$json));
        $finish->invoke($command,$claimed,true,false);
        $row=DB::table('device_event_outbox')->where('id',$claimed->id)->first();
        $this->assertNull($row->published_at); $this->assertNull($row->lease_token); $this->assertSame(2,$row->generation);
        $again=$claim->invoke($command); $this->assertNotNull($again);
        $finish->invoke($command,$again,true,false);
        $this->assertNotNull(DB::table('device_event_outbox')->where('id',$again->id)->value('published_at'));
    }

    public function test_driver_event_cannot_reuse_previously_accepted_telemetry_sequence(): void
    {
        [$org,$device,$msg]=$this->fixture(); $route='fleet.v1.'.$org->id.'.'.$device;
        $telemetry=$msg; $telemetry['message_type']='telemetry'; $telemetry['event_id']=(string) Str::uuid();
        $telemetry['payload']=(object) []; $telemetry['sent_at']=now()->toIso8601String();
        $telemetry['config_version']=1; $telemetry['edge_version']='test'; $telemetry['clock']=(object) ['synchronized'=>true];
        $this->assertSame('stored',app(TelemetryInbox::class)->receive($route.'.up.telemetry',json_encode($telemetry,JSON_THROW_ON_ERROR)));
        $this->assertSame('quarantined',app(DriverAuthenticationInbox::class)->receive($route.'.up.events',json_encode($msg,JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('driver_shifts',0); $this->assertDatabaseCount('device_event_outbox',0);
    }
}
