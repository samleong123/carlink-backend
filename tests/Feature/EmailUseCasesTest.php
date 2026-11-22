<?php
namespace Tests\Feature;

use App\Models\{Membership,Organization,User};
use App\Services\Mail\{DrivingEmailAnalytics,DurableMail,EmailTemplate,NotificationEmails,ScheduledEmails};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt,DB,Mail,Queue};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailUseCasesTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        config(['mail.default'=>'array','app.frontend_url'=>'http://localhost:3000','saas.enforce_subscriptions'=>false]);
        $org=Organization::create(['name'=>'Email Fleet','slug'=>'email-fleet','timezone'=>'Asia/Kuala_Lumpur']);
        $owner=User::create(['email_normalized'=>'owner@example.test','display_name'=>'Owner']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$owner->id,'role'=>'owner','status'=>'active']);
        $user=User::create(['email_normalized'=>'driver@example.test','display_name'=>'Driver']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'driver','status'=>'active']);
        $driver=(string)Str::uuid(); $vehicle=(string)Str::uuid(); $device=(string)Str::uuid(); $installation=(string)Str::uuid(); $credential=(string)Str::uuid(); $shift=(string)Str::uuid();
        DB::table('drivers')->insert(['id'=>$driver,'organization_id'=>$org->id,'user_id'=>$user->id,'name'=>'Driver']);
        DB::table('vehicles')->insert(['id'=>$vehicle,'organization_id'=>$org->id,'registration_number'=>'MAIL123','registration_normalized'=>'MAIL123','registration_country'=>'MY']);
        DB::table('devices')->insert(['id'=>$device,'organization_id'=>$org->id,'serial_number'=>'email-pi']);
        DB::table('device_installations')->insert(['id'=>$installation,'organization_id'=>$org->id,'device_id'=>$device,'vehicle_id'=>$vehicle,'effective_from'=>now()->subDays(10)]);
        DB::table('driver_credentials')->insert(['id'=>$credential,'organization_id'=>$org->id,'driver_id'=>$driver,'credential_hmac'=>str_repeat('a',64),'hash_key_version'=>'1','type'=>'nfc_uid_sha256','issued_at'=>now()]);
        DB::table('driver_shifts')->insert(['id'=>$shift,'organization_id'=>$org->id,'driver_id'=>$driver,'vehicle_id'=>$vehicle,'credential_id'=>$credential,'device_installation_id'=>$installation,'started_at'=>now()->subDays(7),'ended_at'=>now(),'last_authenticated_at'=>now()->subDays(7),'attribution_expires_at'=>now(),'state'=>'closed']);
        return compact('org','owner','user','driver','vehicle','device','installation','shift');
    }
    private function sample(array $f,mixed $at,array $values=[]): string
    {
        $id=(string)Str::uuid(); $inbox=(string)Str::uuid();
        DB::table('telemetry_inbox')->insert(['id'=>$inbox,'organization_id'=>$f['org']->id,'device_id'=>$f['device'],'installation_id'=>$f['installation'],'event_id'=>(string)Str::uuid(),'boot_id'=>(string)Str::uuid(),'sequence'=>1,'occurred_at'=>$at,'received_at'=>$at,'payload_sha256'=>str_repeat('0',64),'raw_payload'=>'{}']);
        DB::table('telemetry_samples')->insert($values+['id'=>$id,'organization_id'=>$f['org']->id,'vehicle_id'=>$f['vehicle'],'installation_id'=>$f['installation'],'inbox_id'=>$inbox,'authorized_shift_id'=>$f['shift'],'occurred_at'=>$at,'received_at'=>$at,'created_at'=>$at,'quality'=>'{}','source_format'=>'pi_packet_v1','speed_kmh'=>36,'fuel_rate_lph'=>3.6,'fuel_rate_source'=>'obd']);
        return $id;
    }
    private function message(string $event,?string $user=null): array
    {
        $receipt=DB::table('email_notifications')->where('event_key',$event)->when($user,fn($q)=>$q->where('user_id',$user))->first(); $this->assertNotNull($receipt);
        $intent=DB::table('mail_outbox')->where('source_id',$receipt->id)->first(); $this->assertNotNull($intent);
        return [$intent,json_decode(Crypt::decryptString($intent->encrypted_message),true,flags:JSON_THROW_ON_ERROR)];
    }
    public function test_templates_are_escaped_light_responsive_and_delivered_as_html_with_text_alternative(): void
    {
        $f=$this->fixture();
        $m=app(EmailTemplate::class)->render('A clean account email','Welcome <script>alert(1)</script>',['User agent'=>'<img src=x onerror=alert(1)>','Unknown'=>null],'http://localhost:3000/console/');
        $this->assertStringNotContainsString('<script>',$m['html']); $this->assertStringContainsString('&lt;img',$m['html']);
        $this->assertStringContainsString('@media only screen and (max-width:600px)',$m['html']); $this->assertStringContainsString('content="light"',$m['html']);
        $this->assertStringContainsString('Not available',$m['body']); $this->assertStringNotContainsString('<link',$m['html']);
        $id=DB::transaction(fn()=>app(NotificationEmails::class)->queue('account.details','test',$f['user']->id,null,'Account details updated','A plain introduction.',['Changed fields'=>'display_name']));
        app(DurableMail::class)->send($id); app(DurableMail::class)->send($id);
        $messages=Mail::mailer()->getSymfonyTransport()->messages(); $this->assertCount(1,$messages);
        $sent=$messages->first()->getOriginalMessage(); $this->assertStringContainsString('<!doctype html>',$sent->getHtmlBody()); $this->assertStringContainsString('Changed fields',$sent->getTextBody());
        $this->assertStringContainsString('http://localhost:3000/#/console',$sent->getHtmlBody()); $this->assertStringContainsString('http://localhost:3000/#/console',$sent->getTextBody());
        $this->assertDatabaseHas('mail_outbox',['id'=>$id,'state'=>'provider_accepted','encrypted_message'=>null]);
    }
    public function test_email_links_reject_untrusted_origin_and_url_credentials(): void
    {
        $this->fixture();
        foreach (['https://evil.example/','http://user@localhost:3000/console/','javascript:alert(1)','http://localhost:3001/console/'] as $url) {
            try { app(EmailTemplate::class)->render('Title','Intro',[],$url); $this->fail('Must reject untrusted email URL.'); }
            catch (\InvalidArgumentException $e) { $this->assertSame('Email links must use the configured frontend origin.',$e->getMessage()); }
        }
    }
    public static function stakeholders(): array { return [['owner'],['driver'],['admin'],['technician']]; }
    #[DataProvider('stakeholders')]
    public function test_each_stakeholder_gets_single_use_html_magic_link_and_localized_login_notice(string $role): void
    {
        config(['mail.default'=>'array','app.frontend_url'=>'http://localhost:3000']);
        $user=User::create(['email_normalized'=>$role.'@example.test','display_name'=>$role]); DB::table('users')->where('id',$user->id)->update(['timezone'=>'America/New_York']);
        if (in_array($role,['admin','technician'],true)) DB::table('saas_admins')->insert(['user_id'=>$user->id,'role'=>$role,'status'=>'active']);
        else { $org=Organization::create(['name'=>'Login','slug'=>'login']); Membership::create(['user_id'=>$user->id,'organization_id'=>$org->id,'role'=>$role,'status'=>'active']); }
        $this->travelTo(CarbonImmutable::parse('2026-11-17T15:04:37Z'));
        $this->postJson('/api/v1/auth/magic-links',['email'=>$user->email_normalized])->assertStatus(202);
        $intent=DB::table('mail_outbox')->where('kind','login')->first(); $message=json_decode(Crypt::decryptString($intent->encrypted_message),true);
        $this->assertStringContainsString('Sign in to CarLink',$message['html']); $this->assertStringContainsString('10 minutes',$message['html']);
        preg_match('/token=([a-f0-9]{64})/',$message['body'],$match); $token=$match[1];
        $headers=['Origin'=>'http://localhost:3000','User-Agent'=>'<script>secret</script> Firefox/130.0','X-Forwarded-For'=>'203.0.113.77'];
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token],$headers)->assertOk();
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token],$headers)->assertUnprocessable();
        $this->assertSame(1,DB::table('email_notifications')->where('event_key','account.login')->count());
        [$notice,$body]=$this->message('account.login'); $this->assertNull($notice->organization_id);
        $this->assertStringContainsString('10:04:37 -05:00 (America/New_York)',$body['body']);
        $this->assertStringContainsString('127.0.0.1',$body['body']); $this->assertStringNotContainsString('203.0.113.77',$body['body']);
        $this->assertStringNotContainsString($token,$body['body']); $this->assertStringNotContainsString('<script>',$body['html']);
        $this->travelBack();
    }

    public function test_login_notice_includes_cloudflare_real_ip_and_country_name(): void
    {
        $user = User::create(['email_normalized' => 'cf-user@example.test', 'display_name' => 'CF User', 'timezone' => 'Asia/Kuala_Lumpur']);
        $this->postJson('/api/v1/auth/magic-links', ['email' => $user->email_normalized])->assertStatus(202);
        $intent = DB::table('mail_outbox')->where('kind', 'login')->orderByDesc('created_at')->first();
        $message = json_decode(Crypt::decryptString($intent->encrypted_message), true);
        preg_match('/token=([a-f0-9]{64})/', $message['body'], $match);
        $token = $match[1];

        // Direct connection from Cloudflare edge IP 173.245.48.15 with CF headers
        $serverVars = ['REMOTE_ADDR' => '173.245.48.15'];
        $headers = [
            'Origin' => 'http://localhost:3000',
            'CF-Connecting-IP' => '175.143.20.30',
            'CF-IPCountry' => 'MY',
            'CF-Ray' => '1234abcd5678ef01-KUL',
        ];
        $this->withServerVariables($serverVars)->postJson('/api/v1/auth/magic-links/consume', ['token' => $token], $headers)->assertOk();

        [$notice, $body] = $this->message('account.login');
        $this->assertStringContainsString('IP address: 175.143.20.30', $body['body']);
        $this->assertStringContainsString('IP country: Malaysia (MY)', $body['body']);
        $this->assertStringContainsString('IP source: trusted_cloudflare', $body['body']);
    }

    public function test_personal_updates_are_self_only_versioned_and_send_mandatory_notice(): void
    {
        $f=$this->fixture(); $this->actingAs($f['user']);
        $this->patchJson('/api/v1/me',['version'=>1,'display_name'=>'New Driver','timezone'=>'Europe/London'])->assertOk()->assertJsonPath('data.version',2);
        $this->patchJson('/api/v1/me',['version'=>1,'display_name'=>'Stale'])->assertStatus(409);
        $this->assertSame(1,DB::table('email_notifications')->where('event_key','account.details')->count());
        [$intent,$body]=$this->message('account.details'); $this->assertStringContainsString('display_name, timezone',$body['body']);
        $this->assertDatabaseHas('users',['id'=>$f['owner']->id,'display_name'=>'Owner']);
    }
    public function test_suspended_scope_receives_status_notice_but_queued_business_mail_is_cancelled(): void
    {
        $f=$this->fixture(); $member=Membership::where('user_id',$f['user']->id)->first();
        $business=DB::transaction(fn()=>app(NotificationEmails::class)->queue('analytics.driver_daily','day',$f['user']->id,$f['org']->id,'Private analytics','Own metrics',[],['permissions'=>['profile.own'],'driver_id'=>$f['driver']]));
        $this->actingAs($f['owner'])->patchJson('/api/v1/organizations/'.$f['org']->id.'/members/'.$member->id,['version'=>1,'status'=>'suspended'])->assertOk();
        [$status,$body]=$this->message('account.status'); $this->assertStringContainsString('Organization membership',$body['body']);
        app(DurableMail::class)->send($status->id); app(DurableMail::class)->send($business);
        $this->assertDatabaseHas('mail_outbox',['id'=>$status->id,'state'=>'provider_accepted']);
        $this->assertDatabaseHas('mail_outbox',['id'=>$business,'state'=>'cancelled','encrypted_message'=>null]);
        $this->artisan('accounts:set-status',['user'=>$f['user']->id,'status'=>'suspended'])->assertSuccessful();
        $global=DB::table('email_notifications')->where('scope','account_status')->whereNull('organization_id')->first();
        $globalMail=DB::table('mail_outbox')->where('source_id',$global->id)->first(); app(DurableMail::class)->send($globalMail->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$globalMail->id,'state'=>'provider_accepted']);
    }
    public function test_business_recipients_recheck_module_permission_email_and_expiry_and_expired_content_is_wiped(): void
    {
        $f=$this->fixture(); $module=User::create(['email_normalized'=>'compliance@example.test','display_name'=>'Compliance']);
        $member=Membership::create(['user_id'=>$module->id,'organization_id'=>$f['org']->id,'role'=>'module_compliance','status'=>'active']);
        DB::transaction(fn()=>app(NotificationEmails::class)->organization('compliance.threshold_crossed','source',$f['org']->id,'Compliance reminder','Renew verified coverage',[],['compliance.write']));
        $this->assertSame(2,DB::table('email_notifications')->count());
        [$intent]=$this->message('compliance.threshold_crossed',$module->id); $member->update(['role'=>'viewer']); app(DurableMail::class)->send($intent->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$intent->id,'state'=>'cancelled']);
        [$ownerIntent]=$this->message('compliance.threshold_crossed',$f['owner']->id); DB::table('users')->where('id',$f['owner']->id)->update(['email_normalized'=>'changed@example.test']); app(DurableMail::class)->send($ownerIntent->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$ownerIntent->id,'state'=>'cancelled']);
        $id=DB::transaction(fn()=>app(NotificationEmails::class)->queue('account.details','expiry',$f['user']->id,null,'Details changed','Changed'));
        config(['mail.default'=>'log']); $this->travel(49)->hours(); $this->artisan('mail:dispatch-pending')->assertSuccessful();
        $this->assertDatabaseHas('mail_outbox',['id'=>$id,'state'=>'cancelled','encrypted_message'=>null]); $this->travelBack();
    }
    public function test_local_daily_weekly_digests_are_deduplicated_and_only_include_own_authorized_samples(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T00:30:00Z')); $f=$this->fixture(); // Monday 08:30 MYT
        $a=$this->sample($f,CarbonImmutable::parse('2026-11-15T15:59:40Z'));
        $b=$this->sample($f,CarbonImmutable::parse('2026-11-15T15:59:50Z'));
        $this->sample($f,CarbonImmutable::parse('2026-11-15T16:00:00Z'),['speed_kmh'=>200]); // Next local day
        $this->sample($f,CarbonImmutable::parse('2026-11-15T15:50:00Z'),['authorized_shift_id'=>null,'speed_kmh'=>199]);
        DB::table('safety_events')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$f['org']->id,'vehicle_id'=>$f['vehicle'],'driver_id'=>$f['driver'],'shift_id'=>$f['shift'],'start_sample_id'=>$a,'end_sample_id'=>$b,'kind'=>'harsh_braking','acceleration_ms2'=>-4,'threshold_ms2'=>3,'occurred_at'=>'2026-11-15 15:59:50','quality'=>'uncalibrated_speed_difference']);
        app(ScheduledEmails::class)->run(); $count=DB::table('email_notifications')->count(); app(ScheduledEmails::class)->run(); $this->assertSame($count,DB::table('email_notifications')->count());
        [$intent,$body]=$this->message('analytics.driver_daily',$f['user']->id);
        $this->assertStringContainsString('Local report date: 2026-11-15',$body['body']); $this->assertStringContainsString('Reported samples: 2',$body['body']);
        $this->assertStringContainsString('Estimated distance (km): 0.1',$body['body']); $this->assertStringContainsString('Estimated fuel (litres): 0.01',$body['body']);
        $this->assertStringContainsString('Highest reported speed (km/h): 36',$body['body']); $this->assertStringContainsString('Harsh braking observations: 1',$body['body']);
        [$weekly]=$this->message('analytics.driver_weekly',$f['user']->id); $this->assertNotNull($weekly);
        [$fleet,$fleetBody]=$this->message('analytics.organization_daily',$f['owner']->id); $this->assertStringContainsString('Reported samples: 3',$fleetBody['body']);
        Queue::fake(); $this->artisan('mail:dispatch-pending')->assertSuccessful(); Queue::assertPushed(\App\Jobs\DeliverMailIntent::class,fn($job)=>$job->intentId===$intent->id && $job->queue==='notifications' && !str_contains(serialize($job),'MAIL123'));
        $this->travelBack();
    }
    public function test_unknown_analytics_stays_null_dst_day_and_attribution_expiry_are_respected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T13:30:00Z')); $f=$this->fixture(); DB::table('organizations')->where('id',$f['org']->id)->update(['timezone'=>'America/New_York']);
        $from=CarbonImmutable::parse('2026-11-01','America/New_York')->startOfDay()->utc(); $to=CarbonImmutable::parse('2026-11-02','America/New_York')->startOfDay()->utc();
        $this->assertSame(25.0,$from->diffInHours($to));
        $summary=app(DrivingEmailAnalytics::class)->summarize($f['org']->id,$from,$to,$f['driver']); $this->assertNull($summary['Estimated distance (km)']); $this->assertNull($summary['Estimated fuel (litres)']);
        $this->sample($f,$from->addHours(24)); DB::table('driver_shifts')->where('id',$f['shift'])->update(['attribution_expires_at'=>$from->addHours(23)]);
        $summary=app(DrivingEmailAnalytics::class)->summarize($f['org']->id,$from,$to,$f['driver']); $this->assertSame(0,$summary['Reported samples']);
        $this->travelBack();
    }
    public function test_appeal_submission_and_result_emails_are_driver_bound_and_exclude_approved_event(): void
    {
        $f=$this->fixture(); $a=$this->sample($f,now()->subSeconds(20)); $b=$this->sample($f,now()->subSeconds(10)); $event=(string)Str::uuid();
        DB::table('safety_events')->insert(['id'=>$event,'organization_id'=>$f['org']->id,'vehicle_id'=>$f['vehicle'],'driver_id'=>$f['driver'],'shift_id'=>$f['shift'],'start_sample_id'=>$a,'end_sample_id'=>$b,'kind'=>'harsh_braking','acceleration_ms2'=>-4,'threshold_ms2'=>3,'occurred_at'=>now()->subSeconds(10),'quality'=>'uncalibrated_speed_difference']);
        $base='/api/v1/organizations/'.$f['org']->id;
        $id=$this->actingAs($f['user'])->postJson($base.'/driver/me/appeals',['event_id'=>$event,'reason'=>'Sensor reading inaccurate'])->assertCreated()->json('data.appeal_id');
        [$submitted,$body]=$this->message('appeal.submitted',$f['user']->id); $this->assertStringContainsString('Sensor reading inaccurate',$body['body']);
        $this->actingAs($f['owner'])->postJson($base.'/safety-appeals/'.$id.'/decision',['version'=>1,'decision'=>'approved','reason'=>'Verified discrepancy'])->assertOk();
        [$result,$resultBody]=$this->message('appeal.decided',$f['user']->id); $this->assertStringContainsString('Verified discrepancy',$resultBody['body']);
        $this->assertDatabaseHas('safety_events',['id'=>$event,'state'=>'excluded']); $this->assertNotSame($submitted->id,$result->id);
    }
    public function test_event_rollback_deduplication_and_daily_preferences_cancel_pending_digest(): void
    {
        $f=$this->fixture();
        try { DB::transaction(function() use($f) { app(NotificationEmails::class)->queue('account.details','rollback',$f['user']->id,null,'Title','Intro'); throw new \RuntimeException('rollback'); }); } catch (\RuntimeException $e) {}
        $this->assertDatabaseCount('email_notifications',0); $this->assertDatabaseCount('mail_outbox',0);
        $id=DB::transaction(fn()=>app(NotificationEmails::class)->queue('analytics.driver_daily','test',$f['user']->id,$f['org']->id,'Digest','Intro',[],['permissions'=>['profile.own'],'driver_id'=>$f['driver']]));
        $duplicate=DB::transaction(fn()=>app(NotificationEmails::class)->queue('analytics.driver_daily','test',$f['user']->id,$f['org']->id,'Digest','Intro',[],['permissions'=>['profile.own'],'driver_id'=>$f['driver']])); $this->assertNull($duplicate);
        DB::table('organization_settings')->insert(['organization_id'=>$f['org']->id,'settings'=>json_encode(['email_driver_daily'=>false])]); app(DurableMail::class)->send($id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$id,'state'=>'cancelled']);
    }
    private function paid(array $f,int $retention=30): void
    {
        $plan=(string)Str::uuid(); $terms=['id'=>$plan,'name'=>'Email plan','price_minor'=>1000,'currency'=>'MYR','period_days'=>30,'vehicle_limit'=>10,'driver_limit'=>10,'retention_days'=>$retention,'cellular_enabled'=>true,'safety_enabled'=>true,'telemetry_min_seconds'=>2];
        DB::table('subscription_plans')->insert($terms);
        DB::table('organization_subscriptions')->insert(['organization_id'=>$f['org']->id,'plan_id'=>$plan,'plan_snapshot'=>json_encode($terms),'status'=>'active','expires_at'=>now()->addDays(20)]);
        config(['saas.enforce_subscriptions'=>true]);
    }
    public function test_compliance_default_custom_days_versioning_and_pending_renewal_suppression(): void
    {
        $f=$this->fixture(); $base='/api/v1/organizations/'.$f['org']->id; $this->actingAs($f['owner']);
        $type=$this->postJson($base.'/compliance-types',['code'=>'roadtax','name'=>'Road tax','subject_type'=>'vehicle'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('compliance_types',['id'=>$type,'warning_days'=>30]);
        $obligation=$this->postJson($base.'/compliance-obligations',['compliance_type_id'=>$type,'subject_type'=>'vehicle','subject_id'=>$f['vehicle']])->assertCreated()->json('data.id');
        $record=$this->postJson($base.'/compliance-obligations/'.$obligation.'/renewals',['valid_from'=>now()->subMonth()->toDateString(),'expires_on'=>now()->addDays(20)->toDateString(),'evidence_reference'=>'Reviewed road tax'],['Idempotency-Key'=>'email-tax-0001'])->assertCreated()->json('data.id');
        app(\App\Services\Alerts\EvaluateVehicleCompliance::class)->run(); $this->assertDatabaseCount('email_notifications',0);
        $this->postJson($base.'/compliance-records/'.$record.'/verify')->assertOk(); app(\App\Services\Alerts\EvaluateVehicleCompliance::class)->run();
        [$intent,$body]=$this->message('compliance.threshold_crossed',$f['owner']->id); $this->assertStringContainsString('Configured warning days: 30',$body['body']);
        $this->patchJson($base.'/compliance-types/'.$type,['version'=>1,'warning_days'=>10])->assertOk()->assertJsonPath('data.version',2);
        $this->patchJson($base.'/compliance-types/'.$type,['version'=>1,'warning_days'=>7])->assertStatus(409);
        $this->patchJson($base.'/compliance-types/'.$type,['version'=>2,'subject_type'=>'driver'])->assertUnprocessable();
        app(DurableMail::class)->send($intent->id); $this->assertDatabaseHas('mail_outbox',['id'=>$intent->id,'state'=>'cancelled']);
        app(\App\Services\Alerts\EvaluateVehicleCompliance::class)->run(); $this->assertDatabaseCount('email_notifications',1);
        $this->patchJson($base.'/compliance-types/'.$type,['version'=>2,'warning_days'=>30])->assertOk(); app(\App\Services\Alerts\EvaluateVehicleCompliance::class)->run();
        $latest=DB::table('email_notifications')->where('event_key','compliance.threshold_crossed')->where('id','<>',DB::table('email_notifications')->first()->id)->first(); $this->assertNotNull($latest);
        $pending=DB::table('mail_outbox')->where('source_id',$latest->id)->first();
        $renewal=$this->postJson($base.'/compliance-obligations/'.$obligation.'/renewals',['valid_from'=>now()->addDays(21)->toDateString(),'expires_on'=>now()->addYear()->toDateString(),'evidence_reference'=>'Reviewed renewal'],['Idempotency-Key'=>'email-tax-0002'])->assertCreated()->json('data.id');
        $this->postJson($base.'/compliance-records/'.$renewal.'/verify')->assertOk();
        // No evaluator between renewal verification and sending: fresh source check must cancel.
        app(DurableMail::class)->send($pending->id); $this->assertDatabaseHas('mail_outbox',['id'=>$pending->id,'state'=>'cancelled']);
    }
    public function test_maintenance_defaults_email_highest_new_stage_and_completed_cycle_cancels(): void
    {
        $f=$this->fixture(); $base='/api/v1/organizations/'.$f['org']->id; $this->actingAs($f['owner']);
        $plan=$this->postJson($base.'/maintenance-plans',['name'=>'Calendar service','service_type'=>'service','interval'=>['calendar_days'=>60,'due_when'=>'any']])->assertCreated()->json('data.id');
        $thresholds=json_decode(DB::table('maintenance_plan_revisions')->where('plan_id',$plan)->value('thresholds'),true);
        $this->assertSame(30,$thresholds[0]['remaining_days_lte']); $this->assertSame(['in_app','email'],$thresholds[0]['channels']);
        $assignment=$this->postJson($base.'/vehicles/'.$f['vehicle'].'/maintenance-plan-assignments',['plan_id'=>$plan,'last_serviced_at'=>now()->subDays(61)->toIso8601String()])->assertCreated()->json('data.id');
        app(\App\Services\Maintenance\EvaluateMaintenance::class)->vehicle($f['org']->id,$f['vehicle']);
        $this->assertSame(2,DB::table('alert_occurrences')->where('kind','maintenance.threshold_crossed')->count());
        [$mail,$body]=$this->message('maintenance.threshold_crossed'); $this->assertStringContainsString('Threshold: due',$body['body']);
        app(\App\Services\Maintenance\EvaluateMaintenance::class)->vehicle($f['org']->id,$f['vehicle']); $this->assertSame(1,DB::table('email_notifications')->where('event_key','maintenance.threshold_crossed')->count());
        $order=$this->postJson($base.'/maintenance/work-orders',['vehicle_id'=>$f['vehicle'],'number'=>'EMAIL-WO','assignment_ids'=>[$assignment]])->assertCreated()->json('data.id');
        $item=$this->getJson($base.'/maintenance/work-orders/'.$order)->assertOk()->json('data.items.0.id');
        $this->postJson($base.'/maintenance/work-orders/'.$order.'/start',['version'=>1])->assertOk();
        $this->postJson($base.'/maintenance/work-orders/'.$order.'/complete',['version'=>2,'performed_at'=>now()->subMinute()->toIso8601String(),'odometer_km'=>10000,'odometer_source'=>'dashboard','workshop_name'=>'Workshop','completed_item_ids'=>[$item]],['Idempotency-Key'=>'email-service-001'])->assertOk();
        app(DurableMail::class)->send($mail->id); $this->assertDatabaseHas('mail_outbox',['id'=>$mail->id,'state'=>'cancelled']);
    }
    public function test_score_decrease_increase_and_repeated_generation_do_not_rescan_digests(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T01:00:00Z')); $f=$this->fixture();
        $a=$this->sample($f,now()->subMinutes(2)); $b=$this->sample($f,now()->subMinutes(2)->addSeconds(10));
        app(ScheduledEmails::class)->run(); $this->assertDatabaseHas('email_score_baselines',['driver_id'=>$f['driver'],'score'=>100]);
        $event=(string)Str::uuid(); DB::table('safety_events')->insert(['id'=>$event,'organization_id'=>$f['org']->id,'vehicle_id'=>$f['vehicle'],'driver_id'=>$f['driver'],'shift_id'=>$f['shift'],'start_sample_id'=>$a,'end_sample_id'=>$b,'kind'=>'harsh_braking','acceleration_ms2'=>-4,'threshold_ms2'=>3,'occurred_at'=>now(),'quality'=>'uncalibrated_speed_difference']);
        $this->travel(1)->days(); app(ScheduledEmails::class)->run(); [$drop,$body]=$this->message('analytics.score_change');
        $this->assertSame('Your driving score decreased',$body['subject']); $this->assertStringContainsString('Previous score: 100',$body['body']); $this->assertStringContainsString('Current score: 95',$body['body']);
        DB::table('safety_events')->where('id',$event)->update(['state'=>'excluded']);
        $this->travel(1)->days(); app(ScheduledEmails::class)->run(); $this->assertSame(2,DB::table('email_notifications')->where('event_key','analytics.score_change')->count());
        $increase=DB::table('mail_outbox')->where('kind','notification')->whereIn('source_id',DB::table('email_notifications')->where('event_key','analytics.score_change')->select('id'))->where('id','<>',$drop->id)->first();
        $this->assertStringContainsString('Your driving score increased',Crypt::decryptString($increase->encrypted_message));
        $this->mock(DrivingEmailAnalytics::class,fn($m)=>$m->shouldNotReceive('summarize')); app(ScheduledEmails::class)->run();
        $this->travelBack();
    }
    public function test_retention_feature_subscription_and_driver_authority_are_rechecked(): void
    {
        $f=$this->fixture(); $this->paid($f,90);
        $queue=fn($source,$policy=[])=>DB::transaction(fn()=>app(NotificationEmails::class)->queue('analytics.driver_daily',$source,$f['user']->id,$f['org']->id,'Driving summary','Authorized history',[], $policy+['permissions'=>['profile.own'],'driver_id'=>$f['driver']]));
        $safety=$queue('feature',['safety_required'=>true]); $retention=$queue('retention',['period_start'=>now()->subDays(60)]);
        $terms=json_decode(DB::table('organization_subscriptions')->value('plan_snapshot'),true); $terms['safety_enabled']=false; $terms['retention_days']=30;
        DB::table('organization_subscriptions')->update(['plan_snapshot'=>json_encode($terms)]);
        foreach ([$safety,$retention] as $id) { app(DurableMail::class)->send($id); $this->assertDatabaseHas('mail_outbox',['id'=>$id,'state'=>'cancelled']); }
        $subscription=$queue('subscription'); DB::table('organization_subscriptions')->update(['status'=>'suspended']); app(DurableMail::class)->send($subscription);
        $this->assertDatabaseHas('mail_outbox',['id'=>$subscription,'state'=>'cancelled']); DB::table('organization_subscriptions')->update(['status'=>'active']);
        $driver=$queue('driver'); DB::table('drivers')->where('id',$f['driver'])->update(['status'=>'suspended']); app(DurableMail::class)->send($driver);
        $this->assertDatabaseHas('mail_outbox',['id'=>$driver,'state'=>'cancelled']);
        $personal=DB::transaction(fn()=>app(NotificationEmails::class)->queue('account.details','mandatory',$f['user']->id,null,'Account updated','Details changed'));
        app(DurableMail::class)->send($personal); $this->assertDatabaseHas('mail_outbox',['id'=>$personal,'state'=>'provider_accepted']);
    }
    public function test_status_supersession_technician_and_global_activation_notices(): void
    {
        $f=$this->fixture(); $member=Membership::where('user_id',$f['user']->id)->first(); $base='/api/v1/organizations/'.$f['org']->id.'/members/'.$member->id; $this->actingAs($f['owner']);
        $this->patchJson($base,['version'=>1,'status'=>'suspended'])->assertOk(); [$old]=$this->message('account.status');
        $this->patchJson($base,['version'=>2,'status'=>'active'])->assertOk(); app(DurableMail::class)->send($old->id); $this->assertDatabaseHas('mail_outbox',['id'=>$old->id,'state'=>'cancelled']);
        DB::table('saas_admins')->insert(['user_id'=>$f['user']->id,'role'=>'technician','status'=>'active','version'=>1]);
        DB::transaction(fn()=>app(NotificationEmails::class)->status('platform',DB::table('saas_admins')->where('user_id',$f['user']->id)->first(),$f['user']->id,null));
        $this->artisan('accounts:set-status',['user'=>$f['user']->id,'status'=>'suspended'])->assertSuccessful();
        $this->artisan('accounts:set-status',['user'=>$f['user']->id,'status'=>'active'])->assertSuccessful();
        $latest=DB::table('mail_outbox')->whereIn('source_id',DB::table('email_notifications')->whereNull('organization_id')->where('scope_version',3)->select('id'))->first(); $this->assertNotNull($latest);
        app(DurableMail::class)->send($latest->id); $this->assertDatabaseHas('mail_outbox',['id'=>$latest->id,'state'=>'provider_accepted']);
    }
    public function test_cross_tenant_recipients_and_resource_references_fail_closed(): void
    {
        $f=$this->fixture(); $other=Organization::create(['name'=>'Other','slug'=>'other']); $user=User::create(['email_normalized'=>'other-email@example.test','display_name'=>'Other']);
        Membership::create(['user_id'=>$user->id,'organization_id'=>$other->id,'role'=>'owner','status'=>'active']);
        $id=DB::transaction(fn()=>app(NotificationEmails::class)->queue('analytics.driver_daily','foreign',$user->id,$f['org']->id,'Private fleet','Intro',[],['permissions'=>['dashboard.read']])); $this->assertNull($id);
        $id=DB::transaction(fn()=>app(NotificationEmails::class)->queue('analytics.driver_daily','other-driver',$f['owner']->id,$f['org']->id,'Private driver','Intro',[],['driver_id'=>$f['driver']])); $this->assertNull($id);
        $id=DB::transaction(fn()=>app(NotificationEmails::class)->queue('device.enabled_linked','foreign-installation',$user->id,$other->id,'Device enabled','Intro',[],['resource_type'=>'installation','resource_id'=>$f['installation']])); $this->assertNull($id);
        $this->assertDatabaseCount('mail_outbox',0);
    }
    public function test_subscription_expiry_notice_is_cancelled_after_renewal_and_no_safety_digest_fields_when_disabled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T01:00:00Z')); $f=$this->fixture(); $this->paid($f);
        $terms=json_decode(DB::table('organization_subscriptions')->value('plan_snapshot'),true); $terms['safety_enabled']=false; DB::table('organization_subscriptions')->update(['plan_snapshot'=>json_encode($terms)]);
        app(ScheduledEmails::class)->run(); [$expiry]=$this->message('subscription.expiry'); [$daily,$body]=$this->message('analytics.driver_daily');
        $this->assertStringNotContainsString('Harsh braking observations:',$body['body']); $this->assertDatabaseCount('email_score_baselines',0);
        DB::table('organization_subscriptions')->update(['expires_at'=>now()->addDays(50),'version'=>2]); app(DurableMail::class)->send($expiry->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$expiry->id,'state'=>'cancelled']); $this->travelBack();
    }
    public function test_multipart_transport_failure_retries_safely_and_security_notice_survives_account_suspension(): void
    {
        $f=$this->fixture();
        $id=DB::transaction(fn()=>app(NotificationEmails::class)->queue('account.details','retry',$f['user']->id,null,'Account details updated','Personal details changed',['Changed fields'=>'Display name']));
        $receipt=DB::table('email_notifications')->first(); $metadata=json_encode($receipt);
        $this->assertStringNotContainsString('Account details updated',$metadata); $this->assertStringNotContainsString('driver@example.test',$metadata);
        $mailer=Mail::getFacadeRoot(); Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('private provider credential'));
        try { app(DurableMail::class)->send($id); $this->fail('Transport failure must be reported.'); }
        catch (\RuntimeException $e) { $this->assertSame('Mail transport failed; durable retry remains pending.',$e->getMessage()); }
        finally { Mail::swap($mailer); }
        $pending=DB::table('mail_outbox')->where('id',$id)->first(); $this->assertSame('pending',$pending->state); $this->assertSame('transport_failure',$pending->last_error);
        $this->assertNotNull($pending->encrypted_message); $this->assertNull($pending->lease_token);
        $this->artisan('accounts:set-status',['user'=>$f['user']->id,'status'=>'suspended'])->assertSuccessful();
        $this->travel(3)->seconds(); app(DurableMail::class)->send($id); app(DurableMail::class)->send($id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$id,'state'=>'provider_accepted','attempts'=>2,'encrypted_message'=>null]);
        $this->assertCount(1,Mail::mailer()->getSymfonyTransport()->messages()); $this->travelBack();
    }
    public function test_default_compliance_types_and_vehicle_registration_without_paid_subscription(): void
    {
        config(['mail.default'=>'array','app.frontend_url'=>'http://localhost:3000','saas.enforce_subscriptions'=>true]);
        $f=$this->fixture();
        $this->actingAs($f['owner']);
        // Check compliance types endpoint automatically initializes default types
        $res = $this->getJson('/api/v1/organizations/'.$f['org']->id.'/compliance-types')->assertOk();
        $codes = collect($res->json('data.items'))->pluck('code')->all();
        $this->assertContains('roadtax',$codes);
        $this->assertContains('insurance',$codes);
        $this->assertContains('puspakom',$codes);

        // Register a new vehicle
        $vRes = $this->postJson('/api/v1/organizations/'.$f['org']->id.'/vehicles',[
            'registration_number'=>'BTR999',
            'registration_country'=>'MY',
            'make'=>'Toyota',
            'model'=>'Hilux',
        ])->assertCreated();
        $newVehicleId = $vRes->json('data.item.id');

        // Verify obligations were automatically created for roadtax, insurance, puspakom
        $obligations = DB::table('compliance_obligations')->where('organization_id',$f['org']->id)->where('vehicle_id',$newVehicleId)->get();
        $this->assertCount(3,$obligations);

        // Verify vehicle.registered notification was queued and can be sent without paid subscription
        [$intent,$body] = $this->message('vehicle.registered');
        $this->assertStringContainsString('BTR999',$body['body']);
        app(DurableMail::class)->send($intent->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$intent->id,'state'=>'provider_accepted']);
    }
    public function test_device_enabled_linked_delivers_without_paid_subscription(): void
    {
        config(['mail.default'=>'array','app.frontend_url'=>'http://localhost:3000','saas.enforce_subscriptions'=>true]);
        $f=$this->fixture();
        DB::table('devices')->where('id',$f['device'])->update(['status'=>'active']);
        app(\App\Services\ManagedDevices::class)->notifyLinked($f['org']->id,$f['device'],$f['vehicle'],$f['installation']);
        [$intent,$body] = $this->message('device.enabled_linked');
        $this->assertStringContainsString('MAIL123',$body['body']);
        app(DurableMail::class)->send($intent->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$intent->id,'state'=>'provider_accepted']);
    }
    public function test_generate_notifications_force_flag_bypasses_digest_hour(): void
    {
        // 02:00 AM local time (before default 8:00 AM digest hour)
        $this->travelTo(CarbonImmutable::parse('2026-11-16T18:00:00Z')); // 02:00 MYT
        $f=$this->fixture();
        $this->sample($f,CarbonImmutable::parse('2026-11-16T15:59:40Z'));
        // Normal run should skip because hour is 2 < 8
        $this->artisan('mail:generate-notifications')->assertSuccessful();
        $this->assertSame(0,DB::table('email_notifications')->where('event_key','analytics.organization_daily')->count());

        // Run with --force should generate notification
        $this->artisan('mail:generate-notifications --force')->assertSuccessful();
        $this->assertSame(1,DB::table('email_notifications')->where('event_key','analytics.organization_daily')->count());
        $this->travelBack();
    }
}
