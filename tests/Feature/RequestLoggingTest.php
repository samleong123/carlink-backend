<?php
namespace Tests\Feature;

use App\Services\RequestLogWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequestLoggingTest extends TestCase
{
    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = sys_get_temp_dir().'/carlink-request-log-'.bin2hex(random_bytes(8));
        config(['logging.request_spool_path'=>$this->spool, 'logging.request_spool_max_bytes'=>1024*1024]);
    }
    protected function tearDown(): void { @unlink($this->spool); parent::tearDown(); }

    private function loggingDatabase(): void
    {
        DB::purge('logging');
        config(['database.connections.logging'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        Schema::connection('logging')->create('request_log_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('request_id'); $t->string('event_type'); $t->timestamp('occurred_at'); $t->json('data');
        });
    }
    public function test_request_events_write_to_separate_database(): void
    {
        $this->loggingDatabase();
        $response = $this->getJson('/api/v1/health/live')->assertOk();
        $this->assertSame(2, DB::connection('logging')->table('request_log_events')->where('request_id',$response->json('request_id'))->count());
    }
    public function test_encrypted_fallback_replays_idempotently(): void
    {
        DB::purge('logging');
        config(['database.connections.logging'=>['driver'=>'unavailable']]);
        $writer = app(RequestLogWriter::class);
        $this->assertTrue($writer->write('11111111-1111-4111-8111-111111111111','started',['method'=>'GET']));
        $encrypted = file_get_contents($this->spool);
        $this->assertStringNotContainsString('11111111-1111-4111-8111-111111111111', $encrypted);
        $this->loggingDatabase();
        $this->artisan('logs:flush-request-spool')->assertSuccessful();
        $this->assertSame(1,DB::connection('logging')->table('request_log_events')->count());
        $this->assertSame('',file_get_contents($this->spool));
        file_put_contents($this->spool,$encrypted);
        $this->artisan('logs:flush-request-spool')->assertSuccessful();
        $this->assertSame(1,DB::connection('logging')->table('request_log_events')->count());
    }
    public function test_request_fails_closed_when_both_sinks_are_unavailable(): void
    {
        DB::purge('logging');
        config(['database.connections.logging'=>['driver'=>'unavailable'], 'logging.request_spool_max_bytes'=>0]);
        $this->getJson('/api/v1/health/live')->assertStatus(503)->assertJsonPath('title','Logging Unavailable');
    }
}
