<?php

namespace Tests\Feature\Http;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FailingCacheStore;
use Tests\TestCase;

/**
 * RDY-T01..T04 - GET /ready is a real readiness probe, not an HTTP mock.
 *
 * Liveness (`/up`) only proves the application booted. Readiness additionally
 * proves the dependencies the deployment actually uses can serve traffic.
 *
 * The redis half is driver-aware on purpose: CI runs the backend suite with the
 * array/sync drivers and no Redis service, so a probe that insists on Redis there
 * marks a healthy instance unavailable - a real CI failure on 2026-10-25.
 */
class ReadinessTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge();

        parent::tearDown();
    }

    public function test_rdy_t01_returns_200_when_the_dependencies_are_up(): void
    {
        $response = $this->getJson('/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok');

        // 'skipped' when the deployment does not use Redis (CI), 'ok' when it does.
        $this->assertContains($response->json('checks.redis'), ['ok', 'skipped']);
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
            ->assertJsonPath('failed', ['database']);
    }

    public function test_rdy_t03_returns_503_naming_redis_when_the_cache_store_fails(): void
    {
        // Force the check on, because this environment may not use Redis.
        config(['readiness.redis' => true]);

        Cache::extend('readiness-failing', fn (): Repository => new Repository(new FailingCacheStore));

        config([
            'cache.stores.readiness-failing' => ['driver' => 'readiness-failing'],
            'cache.default' => 'readiness-failing',
        ]);

        Cache::forgetDriver('readiness-failing');

        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('failed', ['redis']);
    }

    public function test_rdy_t04_skips_redis_when_the_deployment_does_not_use_it(): void
    {
        config([
            'readiness.redis' => null,
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
        ]);

        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.redis', 'skipped')
            ->assertJsonMissingPath('failed');
    }
}
