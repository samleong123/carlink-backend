<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class ProvisionOwnerTest extends TestCase
{
    use RefreshDatabase;
    public function test_owner_provisioning_creates_a_tenant_and_owner(): void
    {
        $this->artisan('carlink:provision-owner',['email'=>'owner@example.com','organization'=>'Test Fleet'])->assertSuccessful();
        $this->assertDatabaseHas('organizations',['slug'=>'test-fleet','name'=>'Test Fleet']);
        $this->assertDatabaseHas('users',['email_normalized'=>'owner@example.com']);
        $this->assertDatabaseHas('memberships',['role'=>'owner','status'=>'active']);
        $this->artisan('carlink:provision-owner',['email'=>'owner@example.com','organization'=>'Test Fleet'])->assertFailed();
        $this->assertSame(1, DB::table('organizations')->count());
    }
}
