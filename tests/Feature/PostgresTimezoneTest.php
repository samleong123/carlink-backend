<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresTimezoneTest extends TestCase
{
    public function test_postgresql_connections_interpret_application_timestamps_in_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL to verify session timestamp semantics.');
        }

        foreach (['pgsql', 'logging'] as $name) {
            $connection = DB::connection($name);
            $zone = $connection->selectOne("SELECT current_setting('TimeZone') AS timezone");
            $this->assertSame('UTC', $zone->timezone);
            $instant = CarbonImmutable::parse('2026-11-16 09:00:00', 'UTC');
            $row = $connection->selectOne('SELECT CAST(? AS timestamptz) AS instant', [$instant]);
            $this->assertTrue($instant->equalTo(CarbonImmutable::parse($row->instant)));
        }
    }
}
