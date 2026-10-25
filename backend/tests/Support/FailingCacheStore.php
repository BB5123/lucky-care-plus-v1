<?php

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A real cache store whose every operation fails. Used by ReadinessTest to
 * prove that GET /ready names the cache/Redis dependency in a 503 without
 * mocking the HTTP layer.
 */
final class FailingCacheStore implements Store
{
    public function get($key)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function many(array $keys)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function put($key, $value, $seconds)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function putMany(array $values, $seconds)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function increment($key, $value = 1)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function decrement($key, $value = 1)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function forever($key, $value)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function touch($key, $seconds)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function forget($key)
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function flush()
    {
        throw new RuntimeException('cache store unavailable');
    }

    public function getPrefix()
    {
        return 'failing-';
    }
}
