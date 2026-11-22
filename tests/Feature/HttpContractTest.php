<?php
namespace Tests\Feature;

use Tests\TestCase;

class HttpContractTest extends TestCase
{
    public function test_health_has_envelope_and_request_id(): void
    {
        $response = $this->getJson('/api/v1/health/live')->assertOk();
        $this->assertSame(['status','timestamp','ip_address','ip_country','request_id','title','desc','data'], array_keys($response->json()));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
    }
    public function test_unknown_route_has_safe_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist')->assertNotFound();
        $this->assertSame(404, $response->json('status'));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
    }
    public function test_non_json_request_body_is_rejected(): void
    {
        $response = $this->call('POST','/api/v1/auth/magic-links',[],[],[],['CONTENT_TYPE'=>'text/plain'],'hello');
        $response->assertStatus(415)->assertJsonPath('status',415);
    }
    public function test_malformed_json_is_rejected(): void
    {
        $response = $this->call('POST','/api/v1/auth/magic-links',[],[],[],['CONTENT_TYPE'=>'application/json'],'{');
        $response->assertStatus(400)->assertJsonPath('status',400);
    }
}
