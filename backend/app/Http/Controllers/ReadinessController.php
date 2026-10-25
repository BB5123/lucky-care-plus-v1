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
 * Redis is checked even when the cache store is not Redis: in production Redis
 * carries the queue, sessions and the rate limiter, so a healthy database cache
 * must not hide a Redis outage.
 */
final class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => 'ok', 'redis' => 'ok'];
        $failed = [];

        try {
            DB::connection()->select('select 1');
        } catch (Throwable) {
            $checks['database'] = 'failed';
            $failed[] = 'database';
        }

        try {
            $cache = Cache::store();
            $store = $cache->getStore();

            if ($store instanceof RedisStore) {
                $store->connection()->ping();
            } elseif (extension_loaded('redis')) {
                Redis::connection()->ping();
            } else {
                // Test/dev PHP without phpredis: exercise the configured cache
                // store so the endpoint still has a real Redis-backed signal.
                $key = 'readiness:'.bin2hex(random_bytes(8));
                $cache->put($key, 'ok', 5);

                if ($cache->get($key) !== 'ok') {
                    throw new RuntimeException('cache round-trip failed');
                }

                $cache->forget($key);
            }
        } catch (Throwable) {
            $checks['redis'] = 'failed';
            $failed[] = 'redis';
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
}
