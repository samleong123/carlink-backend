<?php
namespace Tests\Feature;

use App\Models\{Membership,Organization,User};
use App\Services\{DtcPrivacy,DriverShiftService};
use App\Services\Telemetry\TelemetryInbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt,DB,Http,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasUseCasesTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(bool $paid=true): array
    {
        config(['saas.enforce_subscriptions'=>true,'saas.managed_devices'=>true,'broker.verified_topic_acls'=>true,
            'saas.broker_url'=>'wss://broker.example.test/mqtt','dtc_ai.key'=>'private-provider-key','nfc.hmac_key'=>str_repeat('K',40),'nfc.hmac_key_version'=>'1']);
        $owner=User::create(['email_normalized'=>'owner@example.test','display_name'=>'Owner']);
        $org=Organization::create(['name'=>'Fleet','slug'=>'fleet']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$owner->id,'role'=>'owner','status'=>'active']);
        $admin=User::create(['email_normalized'=>'platform@example.test','display_name'=>'Platform']);
        DB::table('saas_admins')->insert(['user_id'=>$admin->id,'role'=>'admin','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $plan=(string)Str::uuid(); $terms=['id'=>$plan,'name'=>'Pilot','price_minor'=>1000,'currency'=>'MYR','period_days'=>30,'vehicle_limit'=>2,'driver_limit'=>2,'retention_days'=>30,'cellular_enabled'=>true,'safety_enabled'=>true,'telemetry_min_seconds'=>2];
        DB::table('subscription_plans')->insert($terms+['created_at'=>now(),'updated_at'=>now()]);
        if ($paid) DB::table('organization_subscriptions')->insert(['organization_id'=>$org->id,'plan_id'=>$plan,'plan_snapshot'=>json_encode($terms),'status'=>'active','expires_at'=>now()->addDays(30),'created_at'=>now(),'updated_at'=>now()]);
        return [$owner,$org,$admin,$plan];
    }
    private function vehicle(array $f, string $plate='ABC123'): array
    {
        $r=$this->actingAs($f[0])->postJson("/api/v1/organizations/{$f[1]->id}/vehicles",['registration_number'=>$plate,'registration_country'=>'MY','fuel_type'=>'petrol'])->assertCreated();
        return [$r->json('data.item.id'),$r->json('data.device.id')];
    }
    private function activate(array $f, string $device): string
    {
        $url="/api/v1/platform/devices/$device";
        $tech=User::firstOrCreate(['email_normalized'=>'technician@example.test'],['display_name'=>'Technician']);
        DB::table('saas_admins')->updateOrInsert(['user_id'=>$tech->id],['role'=>'technician','status'=>'active']);
        $this->actingAs($tech)->postJson($url.'/bluetooth',['version'=>1,'obd_bluetooth_mac'=>'aa:bb:cc:dd:ee:ff'])->assertOk()->assertJsonPath('data.version',2);
        $this->postJson($url.'/activate',['version'=>2,'installed'=>true,'verified_broker_acls'=>true])->assertOk()->assertJsonPath('data.version',4);
        $this->actingAs($f[2]);
        return DB::table('device_installations')->where('device_id',$device)->value('id');
    }
    private function packet(array $f, string $vehicle, string $device, CarbonImmutable $at, float $speed=0, ?string $local=null): string
    {
        $p=json_decode(file_get_contents(base_path('contracts/fixtures/v1/pi_telemetry.json')),true);
        $p['event_id']=(string)Str::uuid(); $p['timestamp']=$at->format('Y-m-d\TH:i:s\Z'); $p['gps']['timestamp']=$p['timestamp'];
        $p['gateway']['device_id']=DB::table('devices')->where('id',$device)->value('serial_number');
        $p['gateway']['vehicle_id']=DB::table('vehicles')->where('id',$vehicle)->value('registration_number');
        $p['vehicle']['speed_kmh']=$speed; $p['vehicle']['dtc_codes']=['P0730'];
        if ($local) $p['driver_session']['session_id']=$local;
        return app(TelemetryInbox::class)->receive("fleet.v1.{$f[1]->id}.$device.up.telemetry",json_encode($p));
    }
    public function test_platform_authority_is_independent_and_technicians_cannot_administer_plans(): void
    {
        $f=$this->fixture(); $this->actingAs($f[0])->getJson('/api/v1/platform/plans')->assertForbidden();
        $this->actingAs($f[2])->getJson('/api/v1/platform/plans')->assertOk();
        DB::table('saas_admins')->where('user_id',$f[2]->id)->update(['role'=>'technician']);
        $this->getJson('/api/v1/platform/plans')->assertForbidden(); $this->getJson('/api/v1/platform/devices')->assertOk();
        $this->postJson('/api/v1/platform/devices/'.Str::uuid().'/link',['vehicle_id'=>Str::uuid()])->assertForbidden();
    }
    public function test_plan_validation_and_paid_snapshots_are_not_silently_changed(): void
    {
        $f=$this->fixture(); $this->actingAs($f[2]);
        $this->patchJson('/api/v1/platform/plans/'.$f[3],['version'=>1,'retention_days'=>60])->assertUnprocessable();
        $this->patchJson('/api/v1/platform/plans/'.$f[3],['version'=>1,'telemetry_min_seconds'=>1])->assertUnprocessable();
        $this->patchJson('/api/v1/platform/plans/'.$f[3],['version'=>1,'vehicle_limit'=>20])->assertOk();
        $this->patchJson('/api/v1/platform/plans/'.$f[3],['version'=>1,'name'=>'stale'])->assertStatus(409);
        $this->actingAs($f[0])->getJson("/api/v1/organizations/{$f[1]->id}/subscription")->assertOk()->assertJsonPath('data.subscription.plan_snapshot.vehicle_limit',2);
    }
    public function test_unpaid_fleet_is_blocked_but_billing_can_issue_and_confirm_an_invoice_once(): void
    {
        $f=$this->fixture(false); $base="/api/v1/organizations/{$f[1]->id}";
        $this->actingAs($f[0])->getJson($base.'/vehicles')->assertStatus(402);
        $this->getJson($base.'/plans')->assertOk();
        $invoice=$this->postJson($base.'/invoices',['plan_id'=>$f[3]])->assertCreated()->json('data.invoice.id');
        $this->assertDatabaseHas('email_notifications',['event_key'=>'invoice.issued','organization_id'=>$f[1]->id,'user_id'=>$f[0]->id]);
        $this->postJson($base.'/invoices',['plan_id'=>$f[3]])->assertStatus(409);
        $this->assertDatabaseCount('organization_subscriptions',0);
        config(['payment_gateway.token'=>'private-gateway-key','payment_gateway.callback_base_url'=>'https://api.example.test']);
        Http::preventStrayRequests(); Http::fake(fn()=>Http::response(['status'=>200,'data'=>['link'=>'https://gateway.samsam123.name.my/index.html?data=test','gateway_id'=>'invoice-payment','transaction_currency'=>'MYR','sale_amount'=>'10.00','limit'=>1,'type'=>'card']]));
        $this->postJson($base.'/invoices/'.$invoice.'/pay')->assertStatus(202);
        $path=parse_url(Http::recorded()->first()[0]['redirect_url'],PHP_URL_PATH);
        $this->postJson($path,['response'=>['transaction_currency'=>'USD']],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertUnprocessable();
        $this->postJson($path,[],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertOk();
        $end=DB::table('organization_subscriptions')->value('expires_at');
        $this->postJson($path,[],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertStatus(409);
        $this->assertSame($end,DB::table('organization_subscriptions')->value('expires_at'));
        $this->actingAs($f[0])->getJson($base.'/vehicles')->assertOk();
        $this->assertDatabaseHas('billing_invoices',['id'=>$invoice,'status'=>'paid']);
        $this->assertSame(1,DB::table('email_notifications')->where('event_key','subscription.started')->count());
        $invoiceMail=DB::table('mail_outbox')->whereIn('source_id',DB::table('email_notifications')->where('event_key','invoice.issued')->select('id'))->first();
        config(['mail.default'=>'array']); app(\App\Services\Mail\DurableMail::class)->send($invoiceMail->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$invoiceMail->id,'state'=>'cancelled','encrypted_message'=>null]);
    }
    public function test_subscription_suspension_and_expiry_cannot_be_bypassed_by_activation(): void
    {
        $f=$this->fixture(); $url="/api/v1/platform/organizations/{$f[1]->id}/subscription";
        $this->actingAs($f[2])->patchJson($url,['version'=>1,'status'=>'suspended'])->assertOk();
        $this->actingAs($f[0])->getJson("/api/v1/organizations/{$f[1]->id}/vehicles")->assertStatus(402);
        $this->getJson("/api/v1/organizations/{$f[1]->id}/invoices")->assertOk();
        DB::table('organization_subscriptions')->where('organization_id',$f[1]->id)->update(['expires_at'=>now()->subSecond()]);
        $this->actingAs($f[2])->patchJson($url,['version'=>2,'status'=>'active'])->assertStatus(409);
    }
    public function test_vehicle_registration_generates_private_pending_device_and_limits_are_enforced(): void
    {
        $f=$this->fixture(); [$vehicle,$device]=$this->vehicle($f);
        $d=DB::table('devices')->where('id',$device)->first(); $token=Crypt::decryptString($d->auth_token_encrypted);
        $this->assertSame(64,strlen($token)); $this->assertTrue(Str::isUuid($d->obd_uuid)); $this->assertSame('registered',$d->status);
        $this->assertDatabaseHas('email_notifications',['event_key'=>'vehicle.registered','user_id'=>$f[0]->id]);
        $this->assertSame(0,DB::table('email_notifications')->where('event_key','device.enabled_linked')->count());
        $this->vehicle($f,'ABC124'); $base="/api/v1/organizations/{$f[1]->id}";
        $this->postJson($base.'/vehicles',['registration_number'=>'ABC125','registration_country'=>'MY'])->assertStatus(409);
        foreach ([$base.'/devices',$base.'/devices/'.$device,$base.'/vehicles/'.$vehicle] as $url) {
            $response=$this->getJson($url)->assertOk(); $this->assertStringNotContainsString($token,$response->getContent()); $this->assertStringNotContainsString('auth_token_encrypted',$response->getContent());
        }
        $this->postJson($base.'/devices',['serial_number'=>'not-allowed'])->assertForbidden();
        $this->patchJson($base.'/devices/'.$device,['version'=>1,'status'=>'active'])->assertForbidden();
        $this->postJson($base.'/devices/'.$device.'/installations',['vehicle_id'=>$vehicle,'effective_from'=>now()->toIso8601String()])->assertForbidden();
    }
    public function test_activation_uniqueness_relink_and_cross_tenant_boundaries(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); [$v2,$d2]=$this->vehicle($f,'ABC124'); $this->activate($f,$d);
        $enabled=DB::table('email_notifications')->where('event_key','device.enabled_linked')->first(); $this->assertSame($f[0]->id,$enabled->user_id);
        $enabledMail=DB::table('mail_outbox')->where('source_id',$enabled->id)->first(); $copy=json_decode(Crypt::decryptString($enabledMail->encrypted_message),true);
        $this->assertStringContainsString('ABC123',$copy['body']); $this->assertStringContainsString($d,$copy['body']);
        $this->assertStringNotContainsString(Crypt::decryptString(DB::table('devices')->where('id',$d)->value('auth_token_encrypted')),$copy['html']);
        $this->postJson("/api/v1/platform/devices/$d2/link",['vehicle_id'=>$v])->assertStatus(409);
        $this->postJson("/api/v1/platform/devices/$d/link",['vehicle_id'=>$v2])->assertStatus(409);
        $this->travel(2)->seconds();
        $this->postJson("/api/v1/platform/devices/$d/unlink",['reason'=>'replace vehicle'])->assertOk();
        config(['mail.default'=>'array']); app(\App\Services\Mail\DurableMail::class)->send($enabledMail->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$enabledMail->id,'state'=>'cancelled']);
        $this->postJson("/api/v1/platform/devices/$d/link",['vehicle_id'=>$v2])->assertStatus(409);
        $this->postJson("/api/v1/platform/devices/$d2/unlink",['reason'=>'remove pending reservation'])->assertOk();
        $this->postJson("/api/v1/platform/devices/$d/link",['vehicle_id'=>$v2])->assertCreated();
        $this->assertSame(2,DB::table('email_notifications')->where('event_key','device.enabled_linked')->count());
        $this->assertSame(2,DB::table('email_notifications')->where('event_key','device.unlinked')->count());
        $this->assertSame(1,DB::table('device_installations')->where('device_id',$d)->whereNull('effective_to')->count());
        $this->postJson("/api/v1/platform/devices/$d2/link",['vehicle_id'=>Str::uuid()])->assertNotFound();
        $this->getJson("/api/v1/platform/devices/$d")->assertOk()->assertJsonPath('data.device.online',null)->assertJsonPath('data.device.vehicle.registration_number','ABC124')->assertJsonPath('data.device.registration_number','ABC124');
    }
    public function test_dtc_codes_are_scrubbed_before_storage_and_ai_is_transient(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $this->activate($f,$d); $at=CarbonImmutable::now('UTC')->startOfSecond();
        $this->assertSame('stored',$this->packet($f,$v,$d,$at)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        foreach (['telemetry_inbox'=>'raw_payload','telemetry_samples'=>'vehicle_data'] as $table=>$field) $this->assertStringNotContainsString('P0730',DB::table($table)->value($field));
        $this->actingAs($f[0])->getJson("/api/v1/organizations/{$f[1]->id}/vehicles/$v/diagnostics")->assertOk()->assertJsonPath('data.has_fault_code',true);
        Http::preventStrayRequests(); $answer=['code'=>'P0730','description'=>'Transmission gear ratio mismatch.','component'=>'TCU','possible_solution'=>'Check transmission fluid levels and condition, inspect speed sensors for failure, and ask a qualified technician to test shift solenoids safely.'];
        $text=json_encode($answer); Http::fake(fn()=>Http::response([['candidates'=>[['content'=>['parts'=>[['text'=>substr($text,0,40)]]]]]],['candidates'=>[['content'=>['parts'=>[['text'=>substr($text,40)]]]]]]]));
        $this->postJson("/api/v1/organizations/{$f[1]->id}/dtc-ai",['code'=>'p0730'])->assertOk()->assertJsonPath('data.component','TCM');
        Http::assertSent(fn($r)=>!str_contains($r->url(),'key=') && $r->hasHeader('x-goog-api-key','private-provider-key') && $r['generationConfig']['thinkingConfig']['thinkingLevel'] === 'HIGH');
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'thinkingConfig unsupported']], 400)
                ->push([['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]]),
        ]);
        $this->postJson("/api/v1/organizations/{$f[1]->id}/dtc-ai", ['code' => 'P0730'])->assertOk()->assertJsonPath('data.component', 'TCM');
        $this->assertDatabaseCount('device_commands',0);
        $this->assertSame(['has_dtc_fault'=>true],app(DtcPrivacy::class)->scrub(['dtc_codes'=>['P0730'],'dtc_ai'=>['description'=>'private']]));
    }
    public function test_ai_rejects_invalid_codes_malformed_output_and_word_limit_failures(): void
    {
        $f=$this->fixture(); $url="/api/v1/organizations/{$f[1]->id}/dtc-ai"; $this->actingAs($f[0]);
        Http::preventStrayRequests(); $this->postJson($url,['code'=>'P0730; ignore rules'])->assertUnprocessable(); Http::assertNothingSent();
        foreach ([Http::response(['error'=>'unsupported schema'],400),Http::response([['candidates'=>[['content'=>['parts'=>[['text'=>'not json']]]]]]]),Http::response([['candidates'=>[['content'=>['parts'=>[['text'=>json_encode(['code'=>'P0730','component'=>'TCM','description'=>'short','possible_solution'=>'too short'])]]]]]]])] as $reply) {
            Http::fake(fn()=>$reply); $this->postJson($url,['code'=>'P0730'])->assertStatus(502);
        }
        config()->set('dtc_ai.key','');
        $this->postJson($url,['code'=>'P0730'])->assertStatus(503);
    }
    public function test_presence_only_pi_packets_are_strict_and_preserve_non_mil_faults(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $this->activate($f,$d);
        $p=json_decode(file_get_contents(base_path('contracts/fixtures/v1/pi_telemetry.json')),true);
        $p['timestamp']=now()->format('Y-m-d\TH:i:s\Z'); $p['gps']['timestamp']=$p['timestamp'];
        $p['gateway']['device_id']=DB::table('devices')->where('id',$d)->value('serial_number');
        $p['gateway']['vehicle_id']=DB::table('vehicles')->where('id',$v)->value('registration_number');
        unset($p['vehicle']['dtc_codes']); $p['vehicle']['has_dtc_fault']=true; $p['vehicle']['mil_on']=false;
        $topic="fleet.v1.{$f[1]->id}.$d.up.telemetry";
        $this->assertSame('stored',app(TelemetryInbox::class)->receive($topic,json_encode($p)));
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->actingAs($f[0])->getJson("/api/v1/organizations/{$f[1]->id}/vehicles/$v/diagnostics")->assertOk()->assertJsonPath('data.has_fault_code',true);
        $p['event_id']=(string)Str::uuid(); $p['vehicle']['has_dtc_fault']='true';
        $this->assertSame('quarantined',app(TelemetryInbox::class)->receive($topic,json_encode($p)));
        $this->assertDatabaseHas('telemetry_quarantine',['reason'=>'invalid_pi_packet']);
        foreach ([['has_dtc_fault'=>true,'dtc_codes'=>[]],['dtc_codes'=>['P0730'],'has_dtc_fault'=>false]] as $value) {
            $this->assertSame(['has_dtc_fault'=>true],app(DtcPrivacy::class)->scrub($value));
            $this->assertEquals((object)['has_dtc_fault'=>true],app(DtcPrivacy::class)->scrub((object)$value));
        }
    }
    public function test_extended_commands_require_fresh_zero_speed_valid_token_and_nonpersisted_results(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $this->activate($f,$d); $base="/api/v1/organizations/{$f[1]->id}";
        $this->actingAs($f[0])->postJson($base.'/vehicles/'.$v.'/extended-dtc',['acknowledge_interruption'=>true])->assertStatus(409);
        $at=CarbonImmutable::now('UTC')->startOfSecond(); $this->assertSame('stored',$this->packet($f,$v,$d,$at)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $id=$this->postJson($base.'/vehicles/'.$v.'/extended-dtc',['acknowledge_interruption'=>true])->assertStatus(202)->json('data.command_id');
        $edge="/api/v1/device/$d"; $this->getJson($edge.'/commands')->assertUnauthorized();
        $token=Crypt::decryptString(DB::table('devices')->where('id',$d)->value('auth_token_encrypted')); $headers=['Authorization'=>'Bearer '.$token];
        $row=$this->getJson($edge.'/commands',$headers)->assertOk()->json('data.items.0');
        $this->assertSame(hash_hmac('sha256',$row['wire_payload'],$token),$row['signature']);
        $this->postJson($edge.'/commands/'.$id.'/result',['state'=>'running','result_status'=>'started','speed_kmh'=>1,'speed_observed_at'=>now()->toIso8601String()],$headers)->assertStatus(409);
        $this->postJson($edge.'/commands/'.$id.'/result',['state'=>'running','result_status'=>'started','speed_kmh'=>0,'speed_observed_at'=>now()->toIso8601String()],$headers)->assertOk();
        $this->postJson($edge.'/commands/'.$id.'/result',['state'=>'completed','result_status'=>'completed','codes'=>['P0730']],$headers)->assertUnprocessable();
        $this->postJson($edge.'/commands/'.$id.'/result',['state'=>'completed','result_status'=>'completed'],$headers)->assertOk();
        $this->postJson($edge.'/commands/'.$id.'/result',['state'=>'completed','result_status'=>'completed'],$headers)->assertStatus(409);
        $this->travel(181)->seconds(); $this->postJson($base.'/vehicles/'.$v.'/extended-dtc',['acknowledge_interruption'=>true])->assertStatus(409);
    }
    public function test_driver_safety_appeals_and_retention_do_not_expose_another_driver(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $link=$this->activate($f,$d); $base="/api/v1/organizations/{$f[1]->id}";
        $u=User::create(['email_normalized'=>'driver@example.test','display_name'=>'Driver']); Membership::create(['organization_id'=>$f[1]->id,'user_id'=>$u->id,'role'=>'driver','status'=>'active']);
        $driver=$this->actingAs($f[0])->postJson($base.'/drivers',['name'=>'Driver','user_id'=>$u->id])->assertCreated()->json('data.item.id');
        $this->postJson($base.'/drivers/'.$driver.'/credentials',['credential_fingerprint'=>str_repeat('a',64),'credential_type'=>'nfc_uid_sha256'])->assertCreated();
        $this->travel(2)->seconds(); $at=CarbonImmutable::now('UTC')->startOfSecond(); $local=(string)Str::uuid();
        $shift=app(DriverShiftService::class)->acceptAuthenticatedEvent($f[1]->id,$d,['event_id'=>(string)Str::uuid(),'installation_id'=>$link,'local_session_id'=>$local,'credential_type'=>'nfc_uid_sha256','credential_fingerprint'=>str_repeat('a',64),'occurred_at'=>$at->toIso8601String()]);
        $this->assertSame('accepted',$shift['decision']);
        foreach ([0=>0,2=>60] as $seconds=>$speed) { $this->travelTo($at->addSeconds($seconds)); $this->assertSame('stored',$this->packet($f,$v,$d,$at->addSeconds($seconds),$speed,$local)); }
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $event=DB::table('safety_events')->first(); $this->assertSame($driver,$event->driver_id);
        $this->actingAs($u)->getJson($base.'/driver/me/records')->assertOk()->assertJsonCount(2,'data.items');
        $this->getJson($base.'/vehicles/'.$v.'/telemetry')->assertForbidden();
        $this->getJson($base.'/driver/me/score')->assertOk()->assertJsonPath('data.score',95);
        $this->postJson($base.'/driver/me/appeals',['event_id'=>Str::uuid(),'reason'=>'not mine'])->assertNotFound();
        $appeal=$this->postJson($base.'/driver/me/appeals',['event_id'=>$event->id,'reason'=>'Please review sensor accuracy'])->assertCreated()->json('data.appeal_id');
        $this->postJson($base.'/safety-appeals/'.$appeal.'/decision',['version'=>1,'decision'=>'approved','reason'=>'checked'])->assertForbidden();
        $member=Membership::where('organization_id',$f[1]->id)->where('user_id',$u->id)->firstOrFail();
        DB::table('membership_roles')->insert(['organization_id'=>$f[1]->id,'membership_id'=>$member->id,'role_code'=>'module_compliance']);
        $this->getJson($base.'/safety-appeals')->assertOk();
        $this->postJson($base.'/safety-appeals/'.$appeal.'/decision',['version'=>1,'decision'=>'approved','reason'=>'self review'])->assertForbidden();
        $this->assertDatabaseHas('safety_appeals',['id'=>$appeal,'status'=>'pending','version'=>1]);
        $this->actingAs($f[0])->postJson($base.'/safety-appeals/'.$appeal.'/decision',['version'=>1,'decision'=>'approved','reason'=>'Reviewed inaccurate observation'])->assertOk();
        $this->actingAs($u)->getJson($base.'/driver/me/score')->assertOk()->assertJsonPath('data.score',100);
        // Deliberately seed a second driver's authorized test observations in the
        // same tenant/vehicle. Own APIs must not leak them or accept their appeals.
        $other=User::create(['email_normalized'=>'second-driver@example.test','display_name'=>'Second Driver']);
        Membership::create(['organization_id'=>$f[1]->id,'user_id'=>$other->id,'role'=>'driver','status'=>'active']);
        $otherDriver=$this->actingAs($f[0])->postJson($base.'/drivers',['name'=>'Second Driver','user_id'=>$other->id])->assertCreated()->json('data.item.id');
        $otherShift=(array)DB::table('driver_shifts')->first(); $otherShift['id']=(string)Str::uuid(); $otherShift['driver_id']=$otherDriver; $otherShift['state']='closed'; $otherShift['ended_at']=now();
        DB::table('driver_shifts')->insert($otherShift);
        $otherInbox=(array)DB::table('telemetry_inbox')->first(); $otherInbox['id']=(string)Str::uuid(); $otherInbox['event_id']=(string)Str::uuid(); DB::table('telemetry_inbox')->insert($otherInbox);
        $otherSample=(array)DB::table('telemetry_samples')->first(); $otherSample['id']=(string)Str::uuid(); $otherSample['authorized_shift_id']=$otherShift['id']; $otherSample['inbox_id']=$otherInbox['id'];
        DB::table('telemetry_samples')->insert($otherSample);
        $otherEvent=(array)$event; $otherEvent['id']=(string)Str::uuid(); $otherEvent['driver_id']=$otherDriver; $otherEvent['shift_id']=$otherShift['id']; $otherEvent['end_sample_id']=$otherSample['id'];
        DB::table('safety_events')->insert($otherEvent);
        $records=$this->actingAs($u)->getJson($base.'/driver/me/records')->assertOk()->assertJsonCount(2,'data.items');
        $this->assertStringNotContainsString($otherSample['id'],$records->getContent());
        $this->getJson($base.'/driver/me/events')->assertOk()->assertJsonCount(1,'data.items');
        $this->postJson($base.'/driver/me/appeals',['event_id'=>$otherEvent['id'],'reason'=>'not mine'])->assertNotFound();
        DB::table('telemetry_samples')->where('authorized_shift_id','<>',$otherShift['id'])->where('speed_kmh',60)->update(['occurred_at'=>$at->addSeconds(20)]);
        $this->getJson($base.'/driver/me/score')->assertOk()->assertJsonPath('data.score',null)->assertJsonPath('data.eligible_speed_intervals',0);
        $legacy=DB::table('telemetry_inbox')->first(); $raw=json_decode($legacy->raw_payload,true);
        $raw['payload']['driver_context']=['shift_id'=>$otherShift['id']];
        DB::table('telemetry_inbox')->where('id',$legacy->id)->update(['raw_payload'=>json_encode($raw)]);
        $this->travel(31)->days(); $this->artisan('driver-shifts:expire')->assertSuccessful(); $this->artisan('privacy:retain-driver-history')->assertSuccessful();
        $this->assertDatabaseCount('safety_events',0); $this->assertDatabaseCount('safety_appeals',0); $this->assertDatabaseCount('driver_shifts',0);
        $this->assertSame(0,DB::table('telemetry_samples')->whereNotNull('authorized_shift_id')->count());
        foreach (DB::table('telemetry_inbox')->pluck('raw_payload') as $raw) { $this->assertStringNotContainsString('driver_context',$raw); $this->assertStringNotContainsString('driver_session',$raw); }
    }
    public function test_settings_features_frequency_and_module_permissions_are_enforced(): void
    {
        $f=$this->fixture(); $base="/api/v1/organizations/{$f[1]->id}"; $this->actingAs($f[0]);
        $this->patchJson($base.'/settings',['version'=>1,'settings'=>['telemetry_interval_seconds'=>1]])->assertUnprocessable();
        $this->patchJson($base.'/settings',['version'=>1,'settings'=>['telemetry_interval_seconds'=>4,'maf_air_fuel_ratio'=>15,'maf_fuel_density_g_per_litre'=>750]])->assertOk();
        $terms=json_decode(DB::table('organization_subscriptions')->value('plan_snapshot'),true); $terms['safety_enabled']=false; $terms['cellular_enabled']=false; $terms['telemetry_min_seconds']=5;
        DB::table('organization_subscriptions')->update(['plan_snapshot'=>json_encode($terms)]);
        $this->patchJson($base.'/settings',['version'=>2,'settings'=>['telemetry_interval_seconds'=>4]])->assertUnprocessable();
        $this->patchJson($base.'/settings',['version'=>2,'settings'=>['harsh_braking_ms2'=>3]])->assertForbidden();
        $this->getJson($base.'/coverage-map?from='.urlencode(now()->subHour()->toIso8601String()).'&to='.urlencode(now()->toIso8601String()))->assertForbidden();
        Membership::where('organization_id',$f[1]->id)->update(['role'=>'module_coverage']);
        $this->getJson($base.'/drivers')->assertForbidden(); $this->getJson($base.'/vehicles')->assertOk();
    }
    public function test_paid_renewal_cannot_remove_platform_suspension(): void
    {
        $f=$this->fixture(); $base="/api/v1/organizations/{$f[1]->id}";
        $end=CarbonImmutable::parse(DB::table('organization_subscriptions')->value('expires_at'));
        $this->actingAs($f[2])->patchJson("/api/v1/platform/organizations/{$f[1]->id}/subscription",['version'=>1,'status'=>'suspended'])->assertOk();
        $invoice=$this->actingAs($f[0])->postJson($base.'/invoices',['plan_id'=>$f[3]])->assertCreated()->json('data.invoice.id');
        config(['payment_gateway.token'=>'private-gateway-key','payment_gateway.callback_base_url'=>'https://api.example.test']);
        Http::preventStrayRequests(); Http::fake(fn()=>Http::response(['status'=>200,'data'=>['link'=>'https://gateway.samsam123.name.my/index.html?data=test','gateway_id'=>'renewal-payment','transaction_currency'=>'MYR','sale_amount'=>'10.00','limit'=>1,'type'=>'card']]));
        $this->postJson($base.'/invoices/'.$invoice.'/pay')->assertStatus(202);
        $path=parse_url(Http::recorded()->first()[0]['redirect_url'],PHP_URL_PATH);
        $this->postJson($path,[],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertOk();
        $this->assertDatabaseHas('organization_subscriptions',['organization_id'=>$f[1]->id,'status'=>'suspended']);
        $this->assertTrue(CarbonImmutable::parse(DB::table('organization_subscriptions')->value('expires_at'))->equalTo($end->addDays(30)));
        $this->assertSame(1,DB::table('email_notifications')->where('event_key','subscription.renewed')->count());
        $this->getJson($base.'/vehicles')->assertStatus(402);
    }
    public function test_commissioning_and_photos_are_private_and_audited(): void
    {
        Storage::fake('s3')->buildTemporaryUrlsUsing(fn($path,$expires,$options)=>'https://private-test-bucket.s3.ap-southeast-1.amazonaws.com/'.$path.'?test-presign=fixture-only');
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f);
        $base="/api/v1/organizations/{$f[1]->id}"; $platform="/api/v1/platform/devices/$d";
        $this->postJson($platform.'/commissioning')->assertForbidden();
        $this->actingAs($f[2])->postJson($platform.'/commissioning')->assertForbidden();
        $this->postJson($platform.'/bluetooth',['version'=>1,'obd_bluetooth_mac'=>'aa:bb:cc:dd:ee:ff'])->assertForbidden();
        $this->postJson($platform.'/activate',['version'=>1,'installed'=>true,'verified_broker_acls'=>true])->assertForbidden();
        DB::table('saas_admins')->where('user_id',$f[2]->id)->update(['role'=>'technician']);
        $this->postJson($platform.'/bluetooth',['version'=>1,'obd_bluetooth_mac'=>'aa:bb:cc:dd:ee:ff'])->assertOk();
        $response=$this->postJson($platform.'/commissioning')->assertOk();
        $token=Crypt::decryptString(DB::table('devices')->where('id',$d)->value('auth_token_encrypted'));
        $response->assertJsonPath('data.configuration.auth_token',$token);
        $this->assertStringContainsString('carlink-telemetry-agent', $response->json('data.command'));
        $this->assertStringContainsString('github.com/samleong123/carlink-telemetry-agent', $response->json('data.command'));
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->artisan('carlink:commission-device',['organization'=>$f[1]->id,'device'=>$d,'--principal'=>'legacy','--acknowledge-verified-acls'=>true])->assertFailed();
        $u=User::create(['email_normalized'=>'photo-driver@example.test','display_name'=>'Photo Driver']);
        Membership::create(['organization_id'=>$f[1]->id,'user_id'=>$u->id,'role'=>'driver','status'=>'active']);
        $driver=$this->actingAs($f[0])->postJson($base.'/drivers',['name'=>'Photo Driver','user_id'=>$u->id])->assertCreated()->json('data.item.id');
        $image=imagecreatetruecolor(1,1); ob_start(); imagepng($image); $png=base64_encode(ob_get_clean()); imagedestroy($image);
        $this->postJson($base.'/drivers/'.$driver.'/photo',['version'=>1,'content_base64'=>$png])->assertOk()->assertJsonPath('data.version',2);
        $this->assertDatabaseHas('email_notifications',['event_key'=>'account.details','user_id'=>$u->id,'organization_id'=>null]);
        $asset=DB::table('drivers')->where('id',$driver)->value('photo_asset_id'); $path=DB::table('private_assets')->where('id',$asset)->value('object_key'); Storage::disk('s3')->assertExists($path);
        $this->assertTrue(DB::table('audit_outbox')->get()->contains(fn($row)=>json_decode($row->event,true)['action_name'] === 'driver.photo.update'));
        $this->postJson($base.'/drivers/'.$driver.'/photo',['version'=>1,'content_base64'=>$png])->assertStatus(409);
        $this->assertCount(1,Storage::disk('s3')->allFiles('private-assets'));
        $this->actingAs($u)->getJson($base.'/drivers/'.$driver.'/photo')->assertOk()->assertJsonPath('data.item.id',$asset)->assertJsonPath('data.link.valid_for_seconds',300)->assertJsonPath('data.link.requires_authenticated_session',false);
        $this->postJson($base.'/drivers/'.$driver.'/photo',['version'=>2,'content_base64'=>$png])->assertForbidden();
        $other=User::create(['email_normalized'=>'other-photo@example.test','display_name'=>'Other Driver']); Membership::create(['organization_id'=>$f[1]->id,'user_id'=>$other->id,'role'=>'driver','status'=>'active']);
        $this->actingAs($other)->getJson($base.'/drivers/'.$driver.'/photo')->assertForbidden();
        foreach (DB::table('audit_outbox')->get() as $row) $this->assertStringNotContainsString($token,json_encode($row));
    }
    public function test_frequency_csv_and_unlinked_edge_access(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $this->activate($f,$d);
        $base="/api/v1/organizations/{$f[1]->id}"; $at=CarbonImmutable::now('UTC')->startOfSecond();
        $this->assertSame('stored',$this->packet($f,$v,$d,$at));
        $this->assertSame('quarantined',$this->packet($f,$v,$d,$at->addSecond()));
        $this->assertDatabaseHas('telemetry_quarantine',['reason'=>'telemetry_too_frequent']);
        $this->travel(2)->seconds(); $this->assertSame('stored',$this->packet($f,$v,$d,$at->addSeconds(2)));
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        DB::table('telemetry_samples')->update(['network_data'=>json_encode(['operator'=>'=DANGEROUS()','network_type'=>'LTE','rsrp_dbm'=>-120,'sinr_db'=>null])]);
        $query=http_build_query(['from'=>$at->subSecond()->toIso8601String(),'to'=>$at->addSeconds(3)->toIso8601String(),'export'=>1]);
        $csv=$this->actingAs($f[0])->getJson($base.'/coverage-map?'.$query)->assertOk()->assertJsonPath('data.rows',2)->json('data.csv');
        $this->assertStringContainsString("'=DANGEROUS()",$csv); $this->assertStringContainsString('-120',$csv);
        $token=Crypt::decryptString(DB::table('devices')->where('id',$d)->value('auth_token_encrypted'));
        $this->getJson("/api/v1/device/$d/commands",['Authorization'=>'Bearer '.$token])->assertOk();
        $this->actingAs($f[2])->postJson("/api/v1/platform/devices/$d/unlink",['reason'=>'removed'])->assertOk();
        $this->getJson("/api/v1/device/$d/commands",['Authorization'=>'Bearer '.$token])->assertForbidden();
    }
    public function test_twenty_second_telemetry_interval_compatibility(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $link=$this->activate($f,$d); $base="/api/v1/organizations/{$f[1]->id}";
        $this->actingAs($f[0])->patchJson($base.'/settings',['version'=>1,'settings'=>['telemetry_interval_seconds'=>20]])->assertOk();
        $u=User::create(['email_normalized'=>'driver20@example.test','display_name'=>'Driver 20']);
        Membership::create(['organization_id'=>$f[1]->id,'user_id'=>$u->id,'role'=>'driver','status'=>'active']);
        $driver=$this->postJson($base.'/drivers',['name'=>'Driver 20','user_id'=>$u->id])->assertCreated()->json('data.item.id');
        $this->postJson($base.'/drivers/'.$driver.'/credentials',['credential_fingerprint'=>str_repeat('b',64),'credential_type'=>'nfc_uid_sha256'])->assertCreated();
        $this->travel(2)->seconds(); $at=CarbonImmutable::now('UTC')->startOfSecond(); $local=(string)Str::uuid();
        app(DriverShiftService::class)->acceptAuthenticatedEvent($f[1]->id,$d,['event_id'=>(string)Str::uuid(),'installation_id'=>$link,'local_session_id'=>$local,'credential_type'=>'nfc_uid_sha256','credential_fingerprint'=>str_repeat('b',64),'occurred_at'=>$at->toIso8601String()]);
        foreach ([0=>0,20=>72] as $seconds=>$speed) { $this->travelTo($at->addSeconds($seconds)); $this->assertSame('stored',$this->packet($f,$v,$d,$at->addSeconds($seconds),$speed,$local)); }
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->actingAs($u)->getJson($base.'/driver/me/score')->assertOk()
            ->assertJsonPath('data.score',100)->assertJsonPath('data.eligible_speed_intervals',1)->assertJsonPath('data.retention_days',30)->assertJsonPath('data.max_compatible_gap_seconds',25);
        $this->getJson($base.'/driver/me/energy')->assertOk()->assertJsonPath('data.distance_covered_seconds',20)->assertJsonPath('data.max_compatible_gap_seconds',25);
        $this->assertDatabaseHas('vehicle_distance_segments',['vehicle_id'=>$v,'quality'=>'packet_snapshot_estimate']);
        $this->assertDatabaseHas('vehicle_energy_segments',['vehicle_id'=>$v,'quality'=>'packet_snapshot_estimate']);
    }
    public function test_latest_state_network_data_is_gated_by_permission_and_plan(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $this->activate($f,$d); $base="/api/v1/organizations/{$f[1]->id}";
        $at=CarbonImmutable::now('UTC')->startOfSecond();
        $this->assertSame('stored',$this->packet($f,$v,$d,$at)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $res=$this->actingAs($f[0])->getJson($base."/vehicles/$v/latest-state")->assertOk()->assertJsonPath('data.meta.network_gated',false);
        $this->assertNotNull($res->json('data.sample.network_data'));
        $terms=json_decode(DB::table('organization_subscriptions')->value('plan_snapshot'),true); $terms['cellular_enabled']=false;
        DB::table('organization_subscriptions')->update(['plan_snapshot'=>json_encode($terms)]);
        $this->getJson($base."/vehicles/$v/latest-state")->assertOk()->assertJsonPath('data.meta.network_gated',true)->assertJsonPath('data.sample.network_data',null);
        $terms['cellular_enabled']=true; DB::table('organization_subscriptions')->update(['plan_snapshot'=>json_encode($terms)]);
        $viewer=User::create(['email_normalized'=>'dash@example.test','display_name'=>'Dash']);
        Membership::create(['organization_id'=>$f[1]->id,'user_id'=>$viewer->id,'role'=>'module_dashboard','status'=>'active']);
        $this->actingAs($viewer)->getJson($base."/vehicles/$v/latest-state")->assertOk()->assertJsonPath('data.meta.network_gated',true)->assertJsonPath('data.sample.network_data',null);
    }
    public function test_platform_device_details_and_organization_vehicles_gate(): void
    {
        $f=$this->fixture(); [$v,$d]=$this->vehicle($f); $this->activate($f,$d);
        $adminShow=$this->actingAs($f[2])->getJson("/api/v1/platform/devices/$d")->assertOk()->json('data.device');
        $this->assertSame(['id','organization_id','organization_name','status','version','registered_vehicle_id','vehicle','registration_number','make','model','last_received_at','online','online_basis','operator','network_mode'],array_keys($adminShow));
        $vehicles=$this->getJson("/api/v1/platform/organizations/{$f[1]->id}/vehicles")->assertOk()->json('data.items');
        $this->assertCount(1,$vehicles); $this->assertSame($v,$vehicles[0]['id']); $this->assertSame($d,$vehicles[0]['linked_device_id']);
        $this->assertArrayNotHasKey('latitude',$vehicles[0]); $this->assertArrayNotHasKey('driver_id',$vehicles[0]);
        DB::table('saas_admins')->where('user_id',$f[2]->id)->update(['role'=>'technician']);
        $this->getJson("/api/v1/platform/organizations/{$f[1]->id}/vehicles")->assertForbidden();
        $techShow=$this->getJson("/api/v1/platform/devices/$d")->assertOk()->json('data.device');
        $this->assertSame('AA:BB:CC:DD:EE:FF',$techShow['obd_bluetooth_mac']); $this->assertSame($v,$techShow['registered_vehicle_id']); $this->assertSame($v,$techShow['vehicle']['id']);
    }
    public function test_compliance_records_and_maintenance_due_summary(): void
    {
        $f=$this->fixture(); [$v,]=$this->vehicle($f); $base="/api/v1/organizations/{$f[1]->id}"; $this->actingAs($f[0]);
        $type = DB::table('compliance_types')->where('organization_id', $f[1]->id)->where('code', 'roadtax')->value('id')
            ?? $this->postJson($base.'/compliance-types',['code'=>'roadtax','name'=>'Road Tax','subject_type'=>'vehicle','warning_days'=>14])->assertCreated()->json('data.id');
        $ob = DB::table('compliance_obligations')->where('organization_id', $f[1]->id)->where('compliance_type_id', $type)->where('vehicle_id', $v)->value('id')
            ?? $this->postJson($base.'/compliance-obligations',['compliance_type_id'=>$type,'subject_type'=>'vehicle','subject_id'=>$v])->assertCreated()->json('data.id');
        $rec=$this->postJson($base."/compliance-obligations/$ob/renewals",['valid_from'=>'2026-01-01','expires_on'=>'2026-12-31','evidence_reference'=>'doc-1'],['Idempotency-Key'=>'comp-key-1'])->assertCreated()->json('data.id');
        $this->postJson($base."/compliance-records/$rec/verify")->assertOk();
        $statusItems = collect($this->getJson($base.'/compliance/status')->assertOk()->json('data.items'));
        $this->assertSame('ABC123', $statusItems->firstWhere('compliance_type_code', 'roadtax')['subject_name']);
        $obItems = collect($this->getJson($base.'/compliance-obligations')->assertOk()->json('data.items'));
        $this->assertSame('ABC123', $obItems->firstWhere('compliance_type_name', 'Road Tax')['subject_name']);
        $this->getJson($base."/compliance-records?state=verified&vehicle_id=$v")->assertOk()->assertJsonCount(1,'data.items')->assertJsonPath('data.items.0.compliance_type_code','roadtax')->assertJsonPath('data.items.0.subject_name','ABC123');
        $plan=$this->postJson($base.'/maintenance-plans',['name'=>'Oil Change','service_type'=>'oil','interval'=>['distance_km'=>10000,'calendar_months'=>6,'due_when'=>'any']])->assertCreated()->json('data.id');
        $this->postJson($base."/vehicles/$v/maintenance-plan-assignments",['plan_id'=>$plan,'last_serviced_at'=>now()->subDays(10)->toIso8601String()])->assertCreated();
        $this->getJson($base."/maintenance/due-summary?vehicle_id=$v")->assertOk()->assertJsonCount(1,'data.items')->assertJsonPath('data.items.0.vehicle_id',$v)->assertJsonPath('data.items.0.name','Oil Change')->assertJsonPath('data.items.0.registration_number','ABC123');
    }
}
