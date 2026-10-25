<?php

namespace Tests\Feature\Http;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FailingCacheStore;
use Tests\TestCase;

/**
 * RDY-T01..T03 - GET /ready is a real readiness probe, not an HTTP mock.
 *
 * Liveness (`/up`) only proves the application booted. Readiness additionally
 * proves the database and the cache/Redis dependency can serve traffic.
 */
class ReadinessTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge();

        parent::tearDown();
    }

    public function test_rdy_t01_returns_200_when_database_and_redis_are_up(): void
    {
        $response = $this->getJson('/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'ok');
    }

    public function test_rdy_t02_returns_503_naming_the_database_when_it_is_down(): void
    {
        // A closed port is refused immediately; nothing listens on host port 1.
        config([
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 1,
        ]);

        DB::purge('pgsql');

        $response = $this->getJson('/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.database', 'failed')
            ->assertJsonPath('checks.redis', 'ok')
            ->assertJsonPath('failed', ['database']);
    }

    public function test_rdy_t03_returns_503_naming_redis_when_the_cache_store_fails(): void
    {
        Cache::extend('readiness-failing', fn (): Repository => new Repository(new FailingCacheStore));

        config([
            'cache.stores.readiness-failing' => ['driver' => 'readiness-failing'],
            'cache.default' => 'readiness-failing',
        ]);

        Cache::forgetDriver('readiness-failing');

        $response = $this->getJson('/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'failed')
            ->assertJsonPath('failed', ['redis']);
    }
}
