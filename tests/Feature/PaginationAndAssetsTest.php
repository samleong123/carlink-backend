<?php
namespace Tests\Feature;

use App\Models\{Membership,Organization,User};
use App\Services\PrivateAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class PaginationAndAssetsTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(string $slug='files',string $role='owner'): array
    {
        $user=User::create(['email_normalized'=>$slug.'@example.test','display_name'=>$slug]);
        $org=Organization::create(['name'=>$slug,'slug'=>$slug]);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>$role,'status'=>'active']);
        $this->actingAs($user)->withSession(['authenticated_at'=>now()->timestamp]); return [$user,$org,"/api/v1/organizations/{$org->id}"];
    }
    private function privateStorage(): void
    {
        Storage::fake('s3')->buildTemporaryUrlsUsing(function($path,$expires,$options) {
            return 'https://private-test-bucket.s3.ap-southeast-1.amazonaws.com/'.$path.'?'.http_build_query([
                'X-Amz-Algorithm'=>'AWS4-HMAC-SHA256','X-Amz-Signature'=>str_repeat('f',64),'X-Amz-Date'=>now()->format('Ymd\THis\Z'),
                'X-Amz-Credential'=>'test/'.now()->format('Ymd').'/ap-southeast-1/s3/aws4_request','X-Amz-SignedHeaders'=>'host','X-Amz-Expires'=>PrivateAssets::LINK_TTL_SECONDS,
                'response-content-disposition'=>$options['ResponseContentDisposition'],'response-content-type'=>$options['ResponseContentType'],'response-cache-control'=>$options['ResponseCacheControl'],
            ]);
        });
    }
    private function png(): string
    {
        $image=imagecreatetruecolor(2,2); ob_start(); imagepng($image); $bytes=ob_get_clean(); imagedestroy($image); return $bytes;
    }
    private function upload(string $base,string $type,string $id,array $extra=[])
    {
        return $this->postJson($base.'/files',$extra+['target_type'=>$type,'target_id'=>$id,'purpose'=>'document','filename'=>'evidence.png','content_base64'=>base64_encode($this->png())]);
    }
    private function traverse(string $url,int $size,string $key='id'): array
    {
        $cursor=null; $ids=[]; $pages=0;
        do {
            $response=$this->getJson($url.'?'.http_build_query(['per_page'=>$size,'cursor'=>$cursor]))->assertOk();
            $ids=array_merge($ids,array_column($response->json('data.items'),$key)); $cursor=$response->json('data.pagination.next_cursor');
            $this->assertLessThan(20,++$pages,'Pagination must terminate.');
        } while ($cursor);
        $this->assertCount(count($ids),array_unique($ids),'No duplicated records across pages.'); return $ids;
    }
    public function test_registry_and_assignment_pages_reach_all_records_and_validate_cursors(): void
    {
        [$user,$org,$base]=$this->fixture();
        for ($i=0;$i<205;$i++) DB::table('drivers')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$org->id,'name'=>"Driver $i",'created_at'=>now(),'updated_at'=>now()]);
        for ($i=0;$i<105;$i++) {
            DB::table('vehicles')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$org->id,'registration_number'=>"ABC$i",'registration_normalized'=>"ABC$i",'registration_country'=>'MY','created_at'=>now(),'updated_at'=>now()]);
            DB::table('devices')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$org->id,'serial_number'=>"PI$i",'created_at'=>now(),'updated_at'=>now()]);
        }
        $this->assertCount(205,$this->traverse($base.'/drivers',100));
        $this->assertCount(205,$this->traverse($base.'/driver-assignments',200));
        foreach (['vehicles','devices'] as $type) $this->assertCount(105,$this->traverse($base.'/'.$type,100));
        $device=DB::table('devices')->where('organization_id',$org->id)->value('id'); $vehicle=DB::table('vehicles')->where('organization_id',$org->id)->value('id');
        for($i=0;$i<105;$i++) DB::table('device_installations')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$org->id,'device_id'=>$device,'vehicle_id'=>$vehicle,'effective_from'=>now()->subDays(120)->addDays($i),'effective_to'=>now()->subDays(120)->addDays($i+1),'created_at'=>now(),'updated_at'=>now()]);
        $this->assertCount(105,$this->traverse($base.'/devices/'.$device.'/installations',100));
        $first=$this->getJson($base.'/drivers?per_page=100')->assertOk();
        $next=$this->getJson($base.'/drivers?per_page=100&cursor='.urlencode($first->json('data.pagination.next_cursor')))->assertOk();
        $previous=$this->getJson($base.'/drivers?per_page=100&cursor='.urlencode($next->json('data.pagination.previous_cursor')))->assertOk();
        $this->assertSame($first->json('data.items'),$previous->json('data.items'));
        foreach (['0','garbage',rtrim(strtr(base64_encode('{"oops":1,"_pointsToNextItems":true}'),'+/','-_'),'='),rtrim(strtr(base64_encode('{"id":[],"_pointsToNextItems":true}'),'+/','-_'),'=')] as $cursor) $this->getJson($base.'/drivers?cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($base.'/drivers?cursor[]=oops')->assertUnprocessable(); $this->getJson($base.'/drivers?per_page=101')->assertUnprocessable();
        [$other,$otherOrg,$otherBase]=$this->fixture('other'); $this->getJson($otherBase.'/drivers')->assertOk()->assertJsonCount(0,'data.items'); $this->getJson($base.'/drivers')->assertNotFound();
    }
    public function test_private_upload_normalizes_contents_and_issues_direct_s3_links(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture();
        $bytes=$this->png().'<?php echo "hidden payload"; ?>';
        $r=$this->upload($base,'organization',$org->id,['purpose'=>'photo','filename'=>'../bad"name.php','content_base64'=>base64_encode($bytes)])->assertCreated();
        $id=$r->json('data.item.id'); $row=DB::table('private_assets')->where('id',$id)->first();
        $stored=Storage::disk('s3')->get($row->object_key); $this->assertStringNotContainsString('hidden payload',$stored);
        $this->assertSame('private',Storage::disk('s3')->getVisibility($row->object_key)); $this->assertStringNotContainsString('object_key',$r->getContent());
        $this->assertStringNotContainsString('disk',$r->getContent()); $this->assertStringEndsWith('.png',$row->filename);
        $response=$this->postJson($base.'/files/'.$id.'/link',[])->assertOk()->assertJsonPath('data.link.valid_for_seconds',300)->assertJsonPath('data.link.requires_authenticated_session',false);
        $link=$response->json('data.link.url'); $this->assertStringStartsWith('https://private-test-bucket.s3.ap-southeast-1.amazonaws.com/private-assets/',$link);
        parse_str(parse_url($link,PHP_URL_QUERY),$query); $this->assertSame('300',$query['X-Amz-Expires']); $this->assertSame('image/png',$query['response-content-type']);
        $this->assertStringStartsWith('inline;',$query['response-content-disposition']); $this->assertSame('private, no-store',$query['response-cache-control']);
        $this->getJson($base.'/files/'.$id.'/content')->assertOk()->assertJsonPath('data.item.id',$id)->assertJsonPath('data.link.valid_for_seconds',300)->assertHeader('Content-Type','application/json');
        $this->postJson($base.'/files/'.$id.'/revoke',['version'=>2])->assertStatus(409);
        $this->postJson($base.'/files/'.$id.'/revoke',['version'=>1])->assertOk(); $this->postJson($base.'/files/'.$id.'/link',[])->assertNotFound();
        // Issued S3 bearer URLs are not revoked by app state; only expiry/object deletion applies.
        $this->artisan('files:cleanup')->assertSuccessful(); Storage::disk('s3')->assertMissing($row->object_key);
    }
    public function test_link_issuance_checks_tenants_and_current_module_authority(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture();
        $driver=$this->postJson($base.'/drivers',['name'=>'First'])->assertCreated()->json('data.item.id'); DB::table('drivers')->where('id',$driver)->update(['user_id'=>$user->id]);
        $id=$this->upload($base,'driver',$driver)->assertCreated()->json('data.item.id');
        $this->postJson($base.'/files/'.$id.'/link',[])->assertOk();
        $second=User::create(['email_normalized'=>'second@example.test','display_name'=>'Second']); Membership::create(['organization_id'=>$org->id,'user_id'=>$second->id,'role'=>'owner','status'=>'active']);
        $this->actingAs($second)->postJson($base.'/files/'.$id.'/link',[])->assertOk();
        Membership::where('user_id',$user->id)->update(['role'=>'module_compliance']);
        $this->actingAs($user)->postJson($base.'/files/'.$id.'/link',[])->assertForbidden(); $this->getJson($base.'/files?target_type=driver&target_id='.$driver)->assertForbidden();
        [$other,$otherOrg,$otherBase]=$this->fixture('tenant-two');
        $this->upload($otherBase,'driver',$driver)->assertNotFound(); $this->postJson($otherBase.'/files/'.$id.'/link',[])->assertNotFound();
        Membership::where('user_id',$user->id)->update(['role'=>'owner','status'=>'suspended']); $this->actingAs($user)->postJson($base.'/files/'.$id.'/link',[])->assertNotFound();
    }
    public function test_validation_and_storage_fail_closed_without_external_scanning(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture();
        foreach (['not base64',base64_encode('<svg onload="alert(1)"></svg>'),base64_encode('%PDF-invalid')] as $encoded) $this->upload($base,'organization',$org->id,['content_base64'=>$encoded])->assertUnprocessable();
        $this->assertDatabaseCount('private_assets',0);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk=\Mockery::mock()); $disk->shouldReceive('put')->andThrow(new \RuntimeException('private bucket details')); $disk->shouldReceive('delete')->andReturn(false);
        $this->upload($base,'organization',$org->id)->assertStatus(503)->assertJsonPath('data.error.code','FILE_STORAGE_UNAVAILABLE')->assertDontSee('private bucket details');
        $this->assertDatabaseHas('private_assets',['state'=>'failed','purged_at'=>null]);
    }
    public function test_photo_replacement_blocks_new_old_asset_links_and_preserves_legacy_objects(): void
    {
        $this->privateStorage(); Storage::fake('local'); [$user,$org,$base]=$this->fixture();
        $driver=$this->postJson($base.'/drivers',['name'=>'Photo'])->assertCreated()->json('data.item.id'); DB::table('drivers')->where('id',$driver)->update(['user_id'=>$user->id]);
        Storage::disk('local')->put('legacy/photo.png',$this->png()); DB::table('drivers')->where('id',$driver)->update(['photo_path'=>'legacy/photo.png']);
        $this->getJson($base.'/drivers/'.$driver.'/photo')->assertOk()->assertJsonPath('data.reupload_required',true)->assertJsonMissingPath('data.content_base64');
        $payload=['version'=>1,'content_base64'=>base64_encode($this->png())];
        $this->postJson($base.'/drivers/'.$driver.'/photo',$payload)->assertOk()->assertJsonPath('data.version',2);
        $oldId=$this->getJson($base.'/drivers/'.$driver.'/photo')->assertOk()->json('data.item.id');
        $this->postJson($base.'/drivers/'.$driver.'/photo',$payload)->assertStatus(409);
        $this->postJson($base.'/drivers/'.$driver.'/photo',['version'=>2]+$payload)->assertOk(); $this->postJson($base.'/files/'.$oldId.'/link',[])->assertNotFound(); Storage::disk('local')->assertExists('legacy/photo.png');
        $this->artisan('files:cleanup')->assertSuccessful(); $this->assertCount(1,Storage::disk('s3')->allFiles());
        DB::table('drivers')->where('id',$driver)->update(['status'=>'suspended']); Membership::where('user_id',$user->id)->update(['role'=>'driver']);
        $this->getJson($base.'/drivers/'.$driver.'/photo')->assertForbidden();
    }
    public function test_module_evidence_permissions_and_file_pagination(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture();
        $driver=$this->postJson($base.'/drivers',['name'=>'Evidence'])->assertCreated()->json('data.item.id'); DB::table('drivers')->where('id',$driver)->update(['user_id'=>$user->id]);
        $type=(string)Str::uuid(); $obligation=(string)Str::uuid(); $record=(string)Str::uuid();
        DB::table('compliance_types')->insert(['id'=>$type,'organization_id'=>$org->id,'code'=>'insurance','name'=>'Insurance','subject_type'=>'driver']);
        DB::table('compliance_obligations')->insert(['id'=>$obligation,'organization_id'=>$org->id,'compliance_type_id'=>$type,'subject_type'=>'driver','driver_id'=>$driver]);
        DB::table('compliance_records')->insert(['id'=>$record,'organization_id'=>$org->id,'obligation_id'=>$obligation,'cycle_number'=>1,'valid_from'=>now()->toDateString(),'expires_on'=>now()->addYear()->toDateString(),'idempotency_hash'=>str_repeat('a',64),'payload_hash'=>str_repeat('b',64)]);
        Membership::where('user_id',$user->id)->update(['role'=>'module_compliance']);
        $id=$this->upload($base,'compliance_record',$record,['purpose'=>'insurance'])->assertCreated()->json('data.item.id');
        $row=(array)DB::table('private_assets')->where('id',$id)->first();
        for($i=0;$i<105;$i++) DB::table('private_assets')->insert(['id'=>(string)Str::uuid(),'object_key'=>'test-only/'.Str::uuid()]+$row);
        $seen=[]; $cursor=null;
        do { $r=$this->getJson($base.'/files?'.http_build_query(['target_type'=>'compliance_record','target_id'=>$record,'per_page'=>100,'cursor'=>$cursor]))->assertOk(); $seen=array_merge($seen,array_column($r->json('data.items'),'id')); $cursor=$r->json('data.pagination.next_cursor'); } while($cursor);
        $this->assertCount(106,array_unique($seen));
        $this->upload($base,'driver',$driver)->assertForbidden();
        Membership::where('user_id',$user->id)->update(['role'=>'module_driver']); $this->postJson($base.'/files/'.$id.'/link',[])->assertForbidden();
        Membership::where('user_id',$user->id)->update(['role'=>'driver']);
        $this->postJson($base.'/files/'.$id.'/link',[])->assertOk()->assertJsonPath('data.link.requires_authenticated_session',false);
        $this->upload($base,'compliance_record',$record,['purpose'=>'insurance'])->assertForbidden();
        Membership::where('user_id',$user->id)->update(['role'=>'owner']);
        $this->upload($base,'organization',$org->id,['purpose'=>'insurance'])->assertUnprocessable();
    }
    public function test_json_link_retrieval_supports_web_cookie_and_rejects_guest(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture();
        $id=$this->upload($base,'organization',$org->id)->assertCreated()->json('data.item.id');
        $url=$base.'/files/'.$id.'/content';
        $token=str_repeat('e',64);
        DB::table('magic_link_tokens')->insert(['id'=>(string)Str::uuid(),'user_id'=>$user->id,'email_normalized'=>$user->email_normalized,'token_hash'=>hash('sha256',$token),'purpose'=>'login','expires_at'=>now()->addMinutes(10),'return_path'=>'/console/','created_at'=>now(),'updated_at'=>now()]);
        $login=$this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token],['Referer'=>'http://localhost:3000/console/'])->assertOk();
        $cookie=$login->getCookie(config('session.cookie'),false); $this->assertNotNull($cookie);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'),$cookie->getValue())->get($url)->assertOk()->assertHeader('Content-Type','application/json')->assertJsonPath('data.link.requires_authenticated_session',false);
        $this->flushSession(); $this->defaultCookies=[]; $this->unencryptedCookies=[]; \Illuminate\Support\Facades\Auth::forgetGuards(); $this->get($url)->assertUnauthorized();
    }
    public function test_own_trip_pagination_reaches_all_authorized_intersections_without_shared_ids(): void
    {
        [$user,$org,$base]=$this->fixture();
        $driver=$this->postJson($base.'/drivers',['name'=>'Own'])->assertCreated()->json('data.item.id'); DB::table('drivers')->where('id',$driver)->update(['user_id'=>$user->id]);
        $vehicle=$this->postJson($base.'/vehicles',['registration_number'=>'TRIPS','registration_country'=>'MY'])->assertCreated()->json('data.item.id');
        $device=$this->postJson($base.'/devices',['serial_number'=>'trips-pi'])->assertCreated()->json('data.item.id');
        $installation=$this->postJson($base.'/devices/'.$device.'/installations',['vehicle_id'=>$vehicle,'effective_from'=>now()->subDays(2)->toIso8601String()])->assertCreated()->json('data.item.id');
        $credential=(string)Str::uuid(); DB::table('driver_credentials')->insert(['id'=>$credential,'organization_id'=>$org->id,'driver_id'=>$driver,'credential_hmac'=>str_repeat('a',64),'hash_key_version'=>'1','type'=>'nfc_uid_sha256','issued_at'=>now()]);
        $shift=(string)Str::uuid(); DB::table('driver_shifts')->insert(['id'=>$shift,'organization_id'=>$org->id,'driver_id'=>$driver,'vehicle_id'=>$vehicle,'credential_id'=>$credential,'device_installation_id'=>$installation,'started_at'=>now()->subDay(),'ended_at'=>now(),'last_authenticated_at'=>now()->subDay(),'attribution_expires_at'=>now(),'state'=>'closed']);
        $journeyIds=[];
        for ($i=0;$i<120;$i++) {
            $at=now()->subHours(5)->addMinutes($i); $inbox=(string)Str::uuid(); $sample=(string)Str::uuid(); $journey=(string)Str::uuid(); $journeyIds[]=$journey;
            $ownShift=(string)Str::uuid(); DB::table('driver_shifts')->insert(['id'=>$ownShift]+(array)DB::table('driver_shifts')->where('id',$shift)->first());
            DB::table('telemetry_inbox')->insert(['id'=>$inbox,'organization_id'=>$org->id,'device_id'=>$device,'installation_id'=>$installation,'event_id'=>(string)Str::uuid(),'boot_id'=>(string)Str::uuid(),'sequence'=>$i,'occurred_at'=>$at,'received_at'=>$at,'payload_sha256'=>str_repeat('0',64),'raw_payload'=>'{}']);
            DB::table('telemetry_samples')->insert(['id'=>$sample,'organization_id'=>$org->id,'vehicle_id'=>$vehicle,'installation_id'=>$installation,'inbox_id'=>$inbox,'authorized_shift_id'=>$ownShift,'occurred_at'=>$at,'received_at'=>$at,'created_at'=>$at,'quality'=>'{}']);
            DB::table('journeys')->insert(['id'=>$journey,'organization_id'=>$org->id,'vehicle_id'=>$vehicle,'state'=>'ended','started_at'=>$at->copy()->subSeconds(1),'last_observed_at'=>$at->copy()->addSeconds(1),'start_observation_id'=>$sample]);
        }
        Membership::where('user_id',$user->id)->update(['role'=>'driver']);
        $this->assertCount(120,$this->traverse($base.'/driver/me/trips',100,'segment_id'));
        $first=$this->getJson($base.'/driver/me/trips?per_page=100')->assertOk(); $this->assertStringNotContainsString($shift,$first->getContent());
        foreach ($journeyIds as $id) $this->assertStringNotContainsString($id,$first->getContent());
        $cursor=$first->json('data.pagination.next_cursor'); $this->assertNotNull($cursor);
        $next=$this->getJson($base.'/driver/me/trips?per_page=100&cursor='.urlencode($cursor))->assertOk();
        $previous=$this->getJson($base.'/driver/me/trips?per_page=100&cursor='.urlencode($next->json('data.pagination.previous_cursor')))->assertOk();
        $this->assertSame($first->json('data.items'),$previous->json('data.items'));
        $other=User::create(['email_normalized'=>'other-driver@example.test','display_name'=>'Other']); Membership::create(['organization_id'=>$org->id,'user_id'=>$other->id,'role'=>'driver','status'=>'active']);
        DB::table('drivers')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$org->id,'name'=>'Other','user_id'=>$other->id]);
        $this->actingAs($other)->getJson($base.'/driver/me/trips?cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($base.'/driver/me/trips')->assertOk()->assertJsonCount(0,'data.items');
        $this->actingAs($user)->getJson($base.'/driver/me/trips?cursor=broken')->assertUnprocessable();
    }
    public function test_s3_sdk_upload_omits_acl_header_for_owner_enforced_private_buckets(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture(); $calls=[];
        config(['filesystems.disks.s3'=>['driver'=>'s3','key'=>'test-key','secret'=>'test-secret','region'=>'ap-southeast-1','bucket'=>'private-test-bucket','visibility'=>'private','throw'=>true,
            'handler'=>function($command) use(&$calls) { $calls[]=['name'=>$command->getName(),'params'=>$command->toArray()]; return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['@metadata'=>['statusCode'=>200]])); }]]);
        Storage::forgetDisk('s3');
        $this->upload($base,'organization',$org->id)->assertCreated(); $this->assertCount(1,$calls); $this->assertSame('PutObject',$calls[0]['name']);
        $this->assertArrayNotHasKey('ACL',$calls[0]['params']); $this->assertSame('image/png',$calls[0]['params']['ContentType']);
        $this->assertStringStartsWith('private-assets/'.$org->id.'/',$calls[0]['params']['Key']);
    }
    public function test_pdf_upload_needs_no_binary_and_photo_content_limits_are_fail_closed(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture();
        $this->call('POST',$base.'/files',[],[],[],['CONTENT_TYPE'=>'multipart/form-data'])->assertStatus(415)->assertJsonPath('data.error.code','UNSUPPORTED_MEDIA_TYPE');
        $this->call('POST',$base.'/files',[],[],[],['CONTENT_TYPE'=>'application/json'],str_repeat(' ',15728641))->assertStatus(413)->assertJsonPath('data.error.code','PAYLOAD_TOO_LARGE');
        $pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        $id=$this->upload($base,'organization',$org->id,['content_base64'=>base64_encode($pdf),'filename'=>'insurance.pdf'])->assertCreated()->json('data.item.id');
        $link=$this->postJson($base.'/files/'.$id.'/link',['mode'=>'view'])->assertOk()->json('data.link.url');
        parse_str(parse_url($link,PHP_URL_QUERY),$query); $this->assertStringStartsWith('attachment;',$query['response-content-disposition']);
        $this->upload($base,'organization',$org->id,['purpose'=>'photo','content_base64'=>base64_encode($pdf)])->assertUnprocessable();
        $this->upload($base,'organization',$org->id,['purpose'=>'photo','content_base64'=>base64_encode(str_repeat('x',2097153))])->assertUnprocessable();
        $this->assertDatabaseCount('private_assets',1);
    }
    public function test_sdk_presigns_authorized_retrieval_without_fetching_object_bytes(): void
    {
        [$user,$org,$base]=$this->fixture(); $calls=[];
        config(['filesystems.disks.s3'=>['driver'=>'s3','key'=>'test-key','secret'=>'test-secret','region'=>'ap-southeast-1','bucket'=>'private-test-bucket','throw'=>true,
            'handler'=>function($command) use(&$calls) { $calls[]=$command->getName(); return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['@metadata'=>['statusCode'=>200]])); }]]);
        Storage::forgetDisk('s3');
        $id=$this->upload($base,'organization',$org->id)->assertCreated()->json('data.item.id');
        $response=$this->getJson($base.'/files/'.$id.'/content?mode=download')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $url=$response->json('data.link.url'); $this->assertSame(['PutObject'],$calls,'Presigning must not execute GetObject, HeadObject or another S3 request.');
        $this->assertSame('https',parse_url($url,PHP_URL_SCHEME)); $this->assertSame('private-test-bucket.s3.ap-southeast-1.amazonaws.com',parse_url($url,PHP_URL_HOST));
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        $this->assertSame('AWS4-HMAC-SHA256',$query['X-Amz-Algorithm']);
        $this->assertGreaterThan(295,(int)$query['X-Amz-Expires']); $this->assertLessThanOrEqual(300,(int)$query['X-Amz-Expires']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$query['X-Amz-Signature']);
        $this->assertStringStartsWith('test-key/',$query['X-Amz-Credential']); $this->assertStringEndsWith('/ap-southeast-1/s3/aws4_request',$query['X-Amz-Credential']);
        $this->assertSame('host',$query['X-Amz-SignedHeaders']); $this->assertSame('image/png',$query['response-content-type']);
        $this->assertSame('private, no-store',$query['response-cache-control']); $this->assertStringStartsWith('attachment; filename="',$query['response-content-disposition']);
        // Independently reconstruct the AWS SigV4 HMAC, including signed response overrides.
        $signature=$query['X-Amz-Signature']; unset($query['X-Amz-Signature']); ksort($query,SORT_STRING);
        $canonicalQuery=implode('&',array_map(fn($key)=>rawurlencode($key).'='.rawurlencode($query[$key]),array_keys($query)));
        $canonical="GET\n".parse_url($url,PHP_URL_PATH)."\n".$canonicalQuery."\nhost:".parse_url($url,PHP_URL_HOST)."\n\nhost\nUNSIGNED-PAYLOAD";
        $scope=substr($query['X-Amz-Credential'],strlen('test-key/'));
        $key=hash_hmac('sha256',substr($query['X-Amz-Date'],0,8),'AWS4test-secret',true);
        foreach (['ap-southeast-1','s3','aws4_request'] as $part) $key=hash_hmac('sha256',$part,$key,true);
        $this->assertSame($signature,hash_hmac('sha256',"AWS4-HMAC-SHA256\n".$query['X-Amz-Date']."\n".$scope."\n".hash('sha256',$canonical),$key));
        $this->assertStringNotContainsString('test-secret',$response->getContent());
        $this->getJson($base.'/files/'.$id.'/content?mode=invalid')->assertUnprocessable();
        $this->assertSame(['PutObject'],$calls);
    }
    public function test_signing_failures_and_insecure_urls_are_redacted_and_never_fetch_bytes(): void
    {
        $this->privateStorage(); [$user,$org,$base]=$this->fixture();
        $id=$this->upload($base,'organization',$org->id)->assertCreated()->json('data.item.id');
        \Illuminate\Support\Facades\Log::spy();
        foreach (['throw','http://private.test/object?secret=credential','https://user:password@private.test/object','https://private.test/object#secret'] as $value) {
            Storage::disk('s3')->buildTemporaryUrlsUsing(function() use($value) {
                if ($value==='throw') throw new \RuntimeException('PRIVATE-SDK-CREDENTIAL https://private.test/private-assets/key?X-Amz-Signature=secret');
                return $value;
            });
            $response=$this->getJson($base.'/files/'.$id.'/content')->assertStatus(503)->assertJsonPath('data.error.code','FILE_LINK_SIGNING_UNAVAILABLE');
            foreach (['PRIVATE-SDK-CREDENTIAL','private.test','X-Amz-Signature','password'] as $secret) $response->assertDontSee($secret);
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('Private file dependency unavailable',['request_id'=>$response->json('request_id'),'reason_code'=>'FILE_LINK_SIGNING_UNAVAILABLE'])->once();
        }
    }
}
