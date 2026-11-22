<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContractArtifactsTest extends TestCase
{
    public function test_openapi_covers_exactly_registered_api_operations(): void
    {
        $spec=json_decode(file_get_contents(base_path('contracts/openapi.json')),true,flags:JSON_THROW_ON_ERROR);
        $normalize=fn ($path)=>preg_replace('/\{[^}]+\}/','{}',$path);
        $registered=[]; $documented=[];
        foreach (Route::getRoutes() as $route) {
            $uri='/'.$route->uri();
            if (!str_starts_with($uri,'/api/') && $uri !== '/sanctum/csrf-cookie') continue;
            $uris=str_contains($uri,'{resource}') ? array_map(fn ($resource)=>str_replace('{resource}',$resource,$uri),['vehicles','drivers','devices']) : [$uri];
            foreach ($uris as $path) foreach ($route->methods() as $method) if ($method !== 'HEAD') $registered[]=strtolower($method).' '.$normalize($path);
        }
        foreach ($spec['paths'] as $path=>$operations) foreach ($operations as $method=>$operation) {
            if (!in_array($method,['get','post','put','patch','delete','head','options'],true)) continue;
            $paths=str_contains($path,'{resource}') ? array_map(fn ($resource)=>str_replace('{resource}',$resource,$path),['vehicles','drivers','devices']) : [$path];
            foreach ($paths as $expanded) $documented[]=$method.' '.$normalize($expanded);
        }
        sort($registered); sort($documented);
        $this->assertSame($registered,$documented,'Do not document unregistered routes or omit implemented routes.');
        $this->assertSame(['status','timestamp','ip_address','request_id','title','desc','data'],$spec['components']['schemas']['Envelope']['required']);
    }
}
