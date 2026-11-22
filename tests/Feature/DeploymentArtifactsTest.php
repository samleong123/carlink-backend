<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentArtifactsTest extends TestCase
{
    public function test_public_swagger_matches_built_contract_and_does_not_persist_credentials(): void
    {
        $this->assertSame(file_get_contents(base_path('contracts/openapi.json')),file_get_contents(public_path('docs/openapi.json')));
        $js=file_get_contents(public_path('docs/swagger-initializer.js'));
        $this->assertStringContainsString('persistAuthorization: false',$js);
        $this->assertStringContainsString("X-XSRF-TOKEN",$js);
        $this->assertStringContainsString("validatorUrl: null",$js);
    }
    public function test_deployment_secrets_are_excluded_from_images_and_infrastructure_is_private(): void
    {
        $ignore=file_get_contents(base_path('.dockerignore'));
        foreach (['.env','deploy/tls','deploy/*.env','vendor','storage'] as $pattern) $this->assertStringContainsString($pattern,$ignore);
        $compose=file_get_contents(base_path('compose.yaml'));
        foreach (['5432:5432','6379:6379','5671:5671','15675:15675','1883:1883'] as $mapping) $this->assertStringNotContainsString($mapping,$compose);
        $this->assertStringContainsString('127.0.0.1:15672:15672',$compose);
        $this->assertStringContainsString('profiles: [broker]',$compose);
        $nginx=file_get_contents(base_path('deploy/nginx.conf.template'));
        $this->assertStringContainsString('callback/[redacted]',$nginx);
        $this->assertStringContainsString('ssl_reject_handshake on',$nginx);
    }
}
