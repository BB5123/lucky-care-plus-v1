<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Cache\RedisStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Readiness probe, distinct from liveness (`/up`).
 *
 * Liveness answers "the process is up"; readiness answers "this instance can
 * actually serve", which is what a load balancer or `docker healthcheck` needs
 * before it sends traffic. It reports **which** dependency failed and carries no
 * secrets, version strings or stack traces.
 *
 * The database is always checked. Redis is checked only when the deployment
 * depends on it (cache/queue/session driver, or READINESS_CHECK_REDIS) - a probe
 * that fails for a dependency the app does not use marks a healthy instance
 * unavailable, which is exactly what happened on CI before `config/readiness.php`.
 */
final class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => 'ok', 'redis' => $this->redisIsChecked() ? 'ok' : 'skipped'];
        $failed = [];

        try {
            DB::connection()->select('select 1');
        } catch (Throwable) {
            $checks['database'] = 'failed';
            $failed[] = 'database';
        }

        if ($this->redisIsChecked()) {
            try {
                $this->assertRedisAnswers();
            } catch (Throwable) {
                $checks['redis'] = 'failed';
                $failed[] = 'redis';
            }
        }

        if ($failed !== []) {
            return response()->json([
                'status' => 'unavailable',
                'checks' => $checks,
                'failed' => $failed,
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'checks' => $checks,
        ]);
    }

    private function redisIsChecked(): bool
    {
        $configured = config('readiness.redis');

        if ($configured !== null) {
            return $configured === true;
        }

        $drivers = (array) config('readiness.redis_drivers', ['redis']);

        foreach (['cache.default', 'queue.default', 'session.driver'] as $key) {
            if (in_array(config($key), $drivers, true)) {
                return true;
            }
        }

        return false;
    }

    private function assertRedisAnswers(): void
    {
        $cache = Cache::store();
        $store = $cache->getStore();

        if ($store instanceof RedisStore) {
            $store->connection()->ping();

            return;
        }

        if (extension_loaded('redis')) {
            // The deployment may keep its cache on the database, but Redis is
            // still a dependency (queue/session/rate limiter in production).
            // PING it directly so an outage is not hidden by a healthy cache.
            Redis::connection()->ping();

            return;
        }

        // Test/dev PHP without phpredis: exercise the configured cache store so
        // the endpoint still has a real signal for the configured driver.
        $key = 'readiness:'.bin2hex(random_bytes(8));
        $cache->put($key, 'ok', 5);

        if ($cache->get($key) !== 'ok') {
            throw new RuntimeException('cache round-trip failed');
        }

        $cache->forget($key);
    }
}
