<?php
namespace Tests\Feature;

use App\Exceptions\FileDependencyUnavailable;
use Illuminate\Support\Facades\{Log,Route,Storage};
use Tests\TestCase;

class FileDiagnosticsTest extends TestCase
{
    public function test_dependency_errors_preserve_safe_reason_and_request_id_without_global_message_exposure(): void
    {
        Log::spy();
        foreach (['FILE_IMAGE_VALIDATOR_UNAVAILABLE','FILE_STORAGE_UNAVAILABLE','FILE_LINK_SIGNING_UNAVAILABLE'] as $code) {
            Route::get('/test-file-errors/'.$code,fn()=>throw new FileDependencyUnavailable($code));
            $response=$this->getJson('/test-file-errors/'.$code)->assertStatus(503)->assertJsonPath('data.error.code',$code);
            $this->assertSame((new FileDependencyUnavailable($code))->getMessage(),$response->json('desc'));
            $response->assertHeader('X-Request-ID',$response->json('request_id'));
            Log::shouldHaveReceived('warning')->with('Private file dependency unavailable',['request_id'=>$response->json('request_id'),'reason_code'=>$code])->once();
        }
        Route::get('/test-file-errors/unrelated',fn()=>throw new \Symfony\Component\HttpKernel\Exception\HttpException(503,'secret unrelated error'));
        $this->getJson('/test-file-errors/unrelated')->assertStatus(503)->assertJsonPath('data.error.code','UNAVAILABLE')->assertJsonPath('desc','An internal error occurred.')->assertDontSee('secret unrelated error');
    }
    public function test_storage_diagnostics_only_copy_allowlisted_aws_codes_not_sdk_details(): void
    {
        $cause=new \Aws\S3\Exception\S3Exception('secret bucket URL, credentials and contents',new \Aws\Command('PutObject'),['code'=>'AccessDenied','response'=>new \GuzzleHttp\Psr7\Response(403)]);
        $wrapped=new \RuntimeException('secret object key',0,$cause);
        $error=FileDependencyUnavailable::storage($wrapped);
        $this->assertSame(['reason_code'=>'FILE_STORAGE_UNAVAILABLE','provider_code'=>'AccessDenied','provider_status'=>403],$error->diagnosticContext());
        $this->assertNull($error->getPrevious());
        $this->assertStringNotContainsString('secret',$error->getMessage().json_encode($error->diagnosticContext()));
        $unknown=new \Aws\S3\Exception\S3Exception('secret',new \Aws\Command('PutObject'),['code'=>'secret\nforged-log']);
        $this->assertSame(['reason_code'=>'FILE_STORAGE_UNAVAILABLE'],FileDependencyUnavailable::storage($unknown)->diagnosticContext());
    }
    public function test_file_checker_reports_runtime_failures_without_storage_requests_or_secret_output(): void
    {
        config(['filesystems.disks.s3.bucket'=>null,'filesystems.disks.s3.region'=>null,
            'filesystems.disks.s3.key'=>'secret-key','filesystems.disks.s3.secret'=>null,
            'filesystems.disks.s3.endpoint'=>'http://secret-endpoint.test']);
        Storage::shouldReceive('disk')->never();
        $this->artisan('files:check')->expectsOutput('FAIL S3 bucket configured')->expectsOutput('FAIL S3 region configured')
            ->expectsOutput('FAIL AWS credential pair consistent')->expectsOutput('FAIL custom S3 endpoint HTTPS or AWS default')
            ->expectsOutput('Prerequisites only: no S3 request, upload or presigning performed. AWS credentials/IAM, bucket policy, TLS and real file acceptance remain unverified. PDFs receive basic header/ending checks only; uploads are not antivirus-scanned.')->assertFailed();
    }
}
