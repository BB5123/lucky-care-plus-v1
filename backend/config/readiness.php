<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Readiness probe
    |--------------------------------------------------------------------------
    |
    | `/ready` answers "can this instance serve traffic", which is stricter than
    | `/up` (the process booted). It checks the database always, and Redis only
    | when the deployment actually depends on it.
    |
    | `redis` defaults to null = auto-detect: true when the cache, queue or
    | session driver is redis. A deployment running the array/sync drivers has no
    | Redis to check, and a probe that fails for a dependency the app does not use
    | would mark a healthy instance unavailable - which is exactly what happened
    | on CI (no Redis service, the redis extension installed) before this existed.
    |
    | Set READINESS_CHECK_REDIS=true to force the check (recommended in
    | production, where Redis carries the queue and the rate limiter) or `false`
    | to skip it.
    |
    */

    'redis' => env('READINESS_CHECK_REDIS') === null
        ? null
        : filter_var(env('READINESS_CHECK_REDIS'), FILTER_VALIDATE_BOOLEAN),

    'redis_drivers' => ['redis'],

];
