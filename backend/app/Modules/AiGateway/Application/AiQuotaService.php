<?php

namespace App\Modules\AiGateway\Application;

use App\Models\User;
use App\Modules\AiGateway\Models\AiUsageCounter;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Quota enforcement (`.ai/08-AI-GATEWAY.md` section 4).
 *
 * Two independent limits protect the feature:
 *
 *  - the daily ledger in `ai_usage_counters` (default 20 per actor/day);
 *  - a short-lived Laravel rate limiter (default 5 per actor/minute).
 *
 * A rejected attempt never increments the ledger; otherwise a client could
 * lock itself out by retrying after a 429.
 */
final class AiQuotaService
{
    /**
     * @return array{allowed: bool, requests_today: int, remaining: int, reason: ?string}
     */
    public function reserve(Workspace $workspace, User $actor): array
    {
        $dayLimit = max(0, (int) config('ai.limits.per_user_day', 20));
        $minuteLimit = max(0, (int) config('ai.limits.per_user_minute', 5));

        $requestsToday = $this->requestsToday($workspace, $actor);
        $remaining = max(0, $dayLimit - $requestsToday);

        if ($minuteLimit > 0) {
            $key = $this->minuteKey($workspace, $actor);

            if (RateLimiter::tooManyAttempts($key, $minuteLimit)) {
                return [
                    'allowed' => false,
                    'requests_today' => $requestsToday,
                    'remaining' => $remaining,
                    'reason' => 'minute',
                ];
            }
        }

        if ($requestsToday >= $dayLimit) {
            return [
                'allowed' => false,
                'requests_today' => $requestsToday,
                'remaining' => 0,
                'reason' => 'day',
            ];
        }

        if ($minuteLimit > 0) {
            RateLimiter::hit($this->minuteKey($workspace, $actor), 60);
        }

        $counter = AiUsageCounter::query()
            ->where('workspace_id', $workspace->id)
            ->where('actor_id', $actor->id)
            ->whereDate('day', now()->toDateString())
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $counter = new AiUsageCounter([
                'tenant_id' => $workspace->tenant_id,
                'workspace_id' => $workspace->id,
                'actor_id' => $actor->id,
                'day' => now()->toDateString(),
                'requests' => 0,
                'tokens' => 0,
                'cost_minor' => 0,
            ]);
        }

        $counter->requests++;
        $counter->save();

        return [
            'allowed' => true,
            'requests_today' => $counter->requests,
            'remaining' => max(0, $dayLimit - $counter->requests),
            'reason' => null,
        ];
    }

    /**
     * Record token/cost usage after a provider call. A NullProvider reports no
     * tokens, so the ledger keeps its zero values.
     *
     * @return array{requests_today: int, remaining: int}
     */
    public function observe(Workspace $workspace, User $actor, ?int $inputTokens, ?int $outputTokens, ?int $costMinor): array
    {
        $dayLimit = max(0, (int) config('ai.limits.per_user_day', 20));

        $counter = AiUsageCounter::query()
            ->where('workspace_id', $workspace->id)
            ->where('actor_id', $actor->id)
            ->whereDate('day', now()->toDateString())
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            return [
                'requests_today' => 0,
                'remaining' => $dayLimit,
            ];
        }

        $counter->tokens += max(0, (int) $inputTokens) + max(0, (int) $outputTokens);
        $counter->cost_minor += max(0, (int) $costMinor);
        $counter->save();

        return [
            'requests_today' => $counter->requests,
            'remaining' => max(0, $dayLimit - $counter->requests),
        ];
    }

    /**
     * @return array{requests_today: int, remaining: int}
     */
    public function snapshot(Workspace $workspace, User $actor): array
    {
        $dayLimit = max(0, (int) config('ai.limits.per_user_day', 20));
        $requestsToday = $this->requestsToday($workspace, $actor);

        return [
            'requests_today' => $requestsToday,
            'remaining' => max(0, $dayLimit - $requestsToday),
        ];
    }

    private function requestsToday(Workspace $workspace, User $actor): int
    {
        return (int) (AiUsageCounter::query()
            ->where('workspace_id', $workspace->id)
            ->where('actor_id', $actor->id)
            ->whereDate('day', now()->toDateString())
            ->value('requests') ?? 0);
    }

    private function minuteKey(Workspace $workspace, User $actor): string
    {
        return 'ai-summary:'.$workspace->id.':'.$actor->id;
    }
}
