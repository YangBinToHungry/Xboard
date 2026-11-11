<?php

namespace Tests\Feature\Server;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_schema_is_ready_without_mutating_database(): void
    {
        $count = DB::table('migrations')->count();
        $this->artisan('xboard:check-migrations')->assertSuccessful();
        $this->assertSame($count, DB::table('migrations')->count());
    }

    public function test_pending_migration_stops_readiness_without_applying_it(): void
    {
        DB::table('migrations')->where('migration', '2026_11_11_000001_create_traffic_report_receipts')->delete();
        $this->artisan('xboard:check-migrations')->assertFailed();
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_11_11_000001_create_traffic_report_receipts')->count());
    }
}
