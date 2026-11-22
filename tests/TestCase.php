<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private ?string $testSpool = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The ordinary SQLite suite must not append test audits to a deployment's logging DB.
        // Individual logging tests can still install their own isolated sink/schema afterward.
        if (config('database.default') === 'sqlite') {
            DB::purge('logging');
            config(['database.connections.logging' => ['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']]);
        }
        $this->testSpool = sys_get_temp_dir().'/carlink-phpunit-'.bin2hex(random_bytes(12)).'.ndjson';
        config(['logging.request_spool_path' => $this->testSpool]);
    }

    protected function tearDown(): void
    {
        if ($this->testSpool !== null) @unlink($this->testSpool);
        parent::tearDown();
    }
}
