<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FoundationCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::purge('logging');
        config(['database.connections.logging'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        $this->artisan('migrate',['--database'=>'logging','--path'=>'database/migrations/logging','--force'=>true])->assertSuccessful();
    }

    private function member(string $role='owner'): array
    {
        $org=Organization::create(['name'=>'Foundation','slug'=>'foundation']);
        $user=User::create(['email_normalized'=>'foundation@example.test','display_name'=>'Owner']);
        $membership=Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>$role,'status'=>'active']);
        return [$org,$user,$membership];
    }

    public function test_untrusted_headers_cannot_spoof_ip_or_request_identity(): void
    {
        $response=$this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.7'])->withHeaders(['CF-Connecting-IP'=>'192.0.2.1','CF-IPCountry'=>'MY','X-Forwarded-For'=>'192.0.2.2','X-Request-ID'=>'untrusted-id'])->getJson('/api/v1/health/live')->assertOk()->assertJsonPath('ip_address','203.0.113.7')->assertJsonPath('ip_country',null);
        $this->assertNotSame('untrusted-id',$response->json('request_id'));
        $this->assertSame($response->json('request_id'),$response->headers->get('X-Request-ID'));
        $row=DB::connection('logging')->table('http_request_logs')->where('request_id',$response->json('request_id'))->first();
        $context=json_decode($row->context,true);
        $this->assertSame('remote_peer',$context['ip_source']);
        $this->assertNull($context['ip_country']);
        $this->assertSame('untrusted-id',$context['client_request_id']);
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
    }

    public function test_trusted_cloudflare_ipv6_and_full_browser_hints_are_recorded(): void
    {
        config(['ingress.cloudflare_proxies'=>['192.0.2.0/24']]);
        $response=$this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.9','HTTPS'=>'on'])->withHeaders([
            'CF-Connecting-IP'=>'2001:db8::42','CF-IPCountry'=>'MY','CF-Ray'=>'abcd1234-KUL',
            'Sec-CH-UA-Full-Version-List'=>'"Not_A Brand";v="99.0", "Chromium";v="130.0.6723.70", "Google Chrome";v="130.0.6723.70"',
            'Sec-CH-UA-Platform'=>'"Windows"','Sec-CH-UA-Mobile'=>'?0',
        ])->getJson('/api/v1/health/live')->assertOk()->assertJsonPath('ip_address','2001:db8::42')->assertJsonPath('ip_country','MY');
        $row=DB::connection('logging')->table('http_request_logs')->where('request_id',$response->json('request_id'))->first();
        $c=json_decode($row->context,true);
        $this->assertSame('trusted_cloudflare',$c['ip_source']); $this->assertSame('192.0.2.9',$c['peer_ip']);
        $this->assertSame('MY',$c['ip_country']);
        $this->assertSame('Malaysia',$c['ip_country_name']);
        $this->assertSame('Malaysia (MY)',$c['ip_country_display']);
        $this->assertSame('Google Chrome',$c['browser_name']); $this->assertSame('130.0.6723.70',$c['browser_version']);
        $this->assertSame('full',$c['browser_version_precision']); $this->assertFalse($c['is_mobile']);
    }

    public function test_cloudflare_default_cidrs_automatically_resolve_real_origin_ip_and_country(): void
    {
        // 173.245.48.10 is within official Cloudflare IPv4 CIDR 173.245.48.0/20
        $response = $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->withHeaders([
                'CF-Connecting-IP' => '175.143.1.2',
                'CF-IPCountry' => 'MY',
                'CF-Ray' => '1234567890abcdef-KUL',
            ])
            ->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('ip_address', '175.143.1.2')
            ->assertJsonPath('ip_country', 'MY');

        $row = DB::connection('logging')->table('http_request_logs')->where('request_id', $response->json('request_id'))->first();
        $context = json_decode($row->context, true);
        $this->assertSame('175.143.1.2', $context['ip_address']);
        $this->assertSame('MY', $context['ip_country']);
        $this->assertSame('Malaysia', $context['ip_country_name']);
        $this->assertSame('Malaysia (MY)', $context['ip_country_display']);
        $this->assertSame('trusted_cloudflare', $context['ip_source']);
    }

    public function test_cloudflare_peer_172_69_resolves_even_when_config_cache_is_empty(): void
    {
        config(['ingress.cloudflare_proxies' => []]);
        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.69.85.144'])
            ->withHeaders([
                'CF-Connecting-IP' => ' 175.143.10.20 ',
                'CF-IPCountry' => 'my',
                'CF-Ray' => 'abcd1234ef567890-SIN',
            ])
            ->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('ip_address', '175.143.10.20')
            ->assertJsonPath('ip_country', 'MY');

        $row = DB::connection('logging')->table('http_request_logs')->where('request_id', $response->json('request_id'))->first();
        $context = json_decode($row->context, true);
        $this->assertSame('trusted_cloudflare', $context['ip_source']);
        $this->assertSame('172.69.85.144', $context['peer_ip']);
        $this->assertSame('Malaysia (MY)', $context['ip_country_display']);
    }

    public function test_multihop_forwarded_proxy_into_cloudflare_resolves_real_ip_and_country(): void
    {
        config(['ingress.forwarded_proxies' => ['172.18.0.1']]);
        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.1, 172.69.85.144',
                'CF-Connecting-IP' => '175.143.10.21',
                'CF-IPCountry' => 'MY',
                'CF-Ray' => '9876543210fedcba-KUL',
            ])
            ->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('ip_address', '175.143.10.21')
            ->assertJsonPath('ip_country', 'MY');

        $row = DB::connection('logging')->table('http_request_logs')->where('request_id', $response->json('request_id'))->first();
        $context = json_decode($row->context, true);
        $this->assertSame('trusted_cloudflare', $context['ip_source']);
        $this->assertSame('172.18.0.1', $context['peer_ip']);
        $this->assertSame('Malaysia (MY)', $context['ip_country_display']);
    }

    public function test_cloudflare_falls_back_to_xff_and_extracts_country_when_cf_connecting_ip_absent(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.69.85.144'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.1, 175.143.10.22, 172.69.85.144',
                'CF-IPCountry' => 'SG',
                'CF-Ray' => '1122334455667788-SIN',
            ])
            ->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('ip_address', '175.143.10.22')
            ->assertJsonPath('ip_country', 'SG');

        $row = DB::connection('logging')->table('http_request_logs')->where('request_id', $response->json('request_id'))->first();
        $context = json_decode($row->context, true);
        $this->assertSame('trusted_cloudflare', $context['ip_source']);
        $this->assertSame('Singapore (SG)', $context['ip_country_display']);
    }

    public function test_private_docker_proxy_into_cloudflare_resolves_without_explicit_forwarded_proxies(): void
    {
        config(['ingress.forwarded_proxies' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->withHeaders([
                'X-Forwarded-For' => '175.143.10.23, 172.69.85.144',
                'CF-Connecting-IP' => '175.143.10.23',
                'CF-IPCountry' => 'MY',
            ])
            ->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('ip_address', '175.143.10.23')
            ->assertJsonPath('ip_country', 'MY');
    }

    public function test_forwarded_chain_uses_nearest_untrusted_hop_not_leftmost_claim(): void
    {
        config(['ingress.forwarded_proxies'=>['192.0.2.0/24']]);
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.9'])->withHeaders(['X-Forwarded-For'=>'198.51.100.1, 203.0.113.55, 192.0.2.10'])->getJson('/api/v1/health/live')->assertJsonPath('ip_address','203.0.113.55');
    }

    public function test_error_completions_record_actual_status_and_no_secret_body(): void
    {
        $missing=$this->getJson('/api/v1/no-such-route')->assertNotFound();
        $malformed=$this->call('POST','/api/v1/auth/magic-links',[],[],[],['CONTENT_TYPE'=>'application/json'],'{"token":"secret-do-not-log",')->assertStatus(400);
        foreach ([$missing,$malformed] as $response) {
            $row=DB::connection('logging')->table('http_request_logs')->where('request_id',$response->json('request_id'))->first();
            $this->assertSame($response->status(),$row->http_status); $this->assertSame('completed',$row->state);
            $this->assertStringNotContainsString('secret-do-not-log',$row->context);
            $this->assertSame(2,DB::connection('logging')->table('audit_logs')->where('request_id',$row->request_id)->count());
        }
    }

    public function test_permission_edits_apply_immediately_and_tenant_role_assignments_do_not_leak(): void
    {
        [$org,$user,$membership]=$this->member('manager');
        $base='/api/v1/organizations/'.$org->id;
        $this->actingAs($user)->getJson($base.'/vehicles')->assertOk();
        DB::table('role_permissions')->where('role_code','manager')->where('permission_key','vehicles.read')->delete();
        $this->getJson($base.'/vehicles')->assertForbidden();
        $other=Organization::create(['name'=>'Driver scope','slug'=>'driver-scope']);
        $driver=Membership::create(['organization_id'=>$other->id,'user_id'=>$user->id,'role'=>'driver','status'=>'active']);
        $this->assertFalse(app(Permissions::class)->allows($driver,'vehicles.write'));
        $this->getJson('/api/v1/organizations/'.$other->id.'/vehicles')->assertForbidden();
    }

    public function test_database_mutation_audit_commits_and_replays_once(): void
    {
        [$org,$user]=$this->member();
        $response=$this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/vehicles',['registration_number'=>'ABC123','registration_country'=>'MY'])->assertCreated();
        $row=DB::table('audit_outbox')->where('organization_id',$org->id)->first();
        $event=json_decode($row->event,true); $this->assertSame($response->json('request_id'),$event['context']['request_id']);
        $this->artisan('logs:deliver-audit-outbox')->assertSuccessful();
        DB::table('audit_outbox')->where('id',$row->id)->update(['delivered_at'=>null]);
        $this->artisan('logs:deliver-audit-outbox')->assertSuccessful();
        $this->assertSame(1,DB::connection('logging')->table('audit_logs')->where('id',$row->id)->count());
        $this->assertSame(1,DB::connection('logging')->table('log_ingestion_receipts')->where('event_id',$row->id)->count());
    }

    public function test_owner_transfer_requires_recent_email_link_and_preserves_last_owner(): void
    {
        [$org,$user,$owner]=$this->member(); $base='/api/v1/organizations/'.$org->id;
        $other=User::create(['email_normalized'=>'next@example.test','display_name'=>'Next']);
        $target=Membership::create(['organization_id'=>$org->id,'user_id'=>$other->id,'role'=>'viewer','status'=>'active']);
        $this->actingAs($user)->withHeaders(['Origin'=>'http://localhost:3000'])->withSession(['authenticated_at'=>now()->timestamp])->postJson($base.'/ownership-transfers',['membership_id'=>$target->id,'version'=>1])->assertForbidden();
        $this->withSession(['email_link_verified_at'=>now()->timestamp])->patchJson($base.'/members/'.$owner->id,['version'=>1,'status'=>'suspended'])->assertConflict();
        $this->withSession(['email_link_verified_at'=>now()->timestamp])->postJson($base.'/ownership-transfers',['membership_id'=>$target->id,'version'=>1])->assertOk();
        $this->assertSame('owner',$target->fresh()->role); $this->assertSame('admin',$owner->fresh()->role);
        $this->withSession(['email_link_verified_at'=>now()->timestamp])->postJson($base.'/ownership-transfers',['membership_id'=>$target->id,'version'=>1])->assertForbidden();
    }

    public function test_versioned_settings_and_log_queries_are_tenant_scoped(): void
    {
        [$org,$user]=$this->member(); $base='/api/v1/organizations/'.$org->id;
        $this->actingAs($user)->patchJson($base.'/settings',['version'=>1,'settings'=>['device_offline_after_s'=>180]])->assertOk();
        $this->patchJson($base.'/settings',['version'=>1,'settings'=>['device_offline_after_s'=>200]])->assertConflict();
        $this->patchJson($base.'/settings',['version'=>2,'settings'=>['arbitrary_shell'=>'bad']])->assertUnprocessable();
        $this->getJson($base.'/settings')->assertJsonPath('data.settings.device_offline_after_s',180);
        $query='?from='.urlencode(now()->subHour()->toIso8601String()).'&to='.urlencode(now()->addHour()->toIso8601String());
        $response=$this->getJson($base.'/request-logs'.$query)->assertOk();
        foreach ($response->json('data.items') as $row) $this->assertSame($org->id,$row['organization_id']);
        $this->assertGreaterThan(0,count($response->json('data.items')));
    }

    public function test_audit_outbox_failure_rolls_back_business_mutation(): void
    {
        [$org,$user]=$this->member();
        DB::statement("CREATE TEMP TRIGGER fail_audit BEFORE INSERT ON audit_outbox BEGIN SELECT RAISE(ABORT, 'simulated audit disk failure'); END");
        try {
            $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/vehicles',['registration_number'=>'ROLLBACK1','registration_country'=>'MY'])->assertStatus(500);
            $this->assertDatabaseCount('vehicles',0); $this->assertDatabaseCount('audit_outbox',0);
            $this->assertSame(1,DB::connection('logging')->table('audit_logs')->where('outcome','failed')->count());
        } finally { DB::statement('DROP TRIGGER fail_audit'); }
    }

    public function test_action_spool_replays_encrypted_events_and_retains_conflicting_identity(): void
    {
        $path=storage_path('framework/testing-audit-'.bin2hex(random_bytes(8)).'.ndjson');
        $writer=app(\App\Services\RequestLogWriter::class);
        $event=app(\App\Services\ActionAudit::class)->event('test.replay','succeeded','scheduler',['browser_absence_reason'=>'non_browser_execution']);
        config(['logging.request_spool_path'=>$path]);
        try {
            $connection=config('database.connections.logging');
            DB::purge('logging'); config(['database.connections.logging'=>['driver'=>'unavailable']]);
            $this->assertTrue($writer->action($event));
            $encrypted=file_get_contents($path); $this->assertStringNotContainsString('test.replay',$encrypted);
            DB::purge('logging'); config(['database.connections.logging'=>$connection]);
            $this->artisan('migrate',['--database'=>'logging','--path'=>'database/migrations/logging','--force'=>true])->assertSuccessful();
            $this->artisan('logs:flush-request-spool')->assertSuccessful();
            $this->assertSame(0,filesize($path));
            file_put_contents($path,$encrypted); $this->artisan('logs:flush-request-spool')->assertSuccessful();
            $this->assertSame(1,DB::connection('logging')->table('audit_logs')->where('id',$event['id'])->count());
            $changed=$event; $changed['outcome']='failed';
            file_put_contents($path,\Illuminate\Support\Facades\Crypt::encryptString(json_encode($changed,JSON_THROW_ON_ERROR))."\n");
            $this->artisan('logs:flush-request-spool')->assertFailed();
            $this->assertNotEmpty(file_get_contents($path));
        } finally { @unlink($path); }
    }

    public function test_member_mutation_and_ownership_target_ids_cannot_cross_tenants(): void
    {
        [$org,$user]=$this->member();
        $other=Organization::create(['name'=>'Other','slug'=>'other']);
        $target=Membership::create(['organization_id'=>$other->id,'user_id'=>$user->id,'role'=>'viewer','status'=>'active']);
        $base='/api/v1/organizations/'.$org->id;
        $this->actingAs($user)->withHeaders(['Origin'=>'http://localhost:3000'])->withSession(['authenticated_at'=>now()->timestamp,'email_link_verified_at'=>now()->timestamp]);
        $this->patchJson($base.'/members/'.$target->id,['version'=>1,'roles'=>['admin']])->assertNotFound();
        $this->postJson($base.'/ownership-transfers',['membership_id'=>$target->id,'version'=>1])->assertNotFound();
        $this->assertSame('viewer',$target->fresh()->role);
        $this->getJson($base.'/members?per_page=1')->assertOk()->assertJsonCount(1,'data.items')->assertJsonPath('data.pagination.per_page',1);
    }
}
