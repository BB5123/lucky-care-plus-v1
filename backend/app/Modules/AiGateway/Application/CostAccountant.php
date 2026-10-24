<?php

namespace App\Modules\AiGateway\Application;

use App\Models\User;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Models\AiUsageCounter;
use App\Modules\Workspace\Models\Workspace;

/**
 * Token → cost conversion and usage persistence
 * (`.ai/08-AI-GATEWAY.md` section 4).
 *
 * Prices are minor units per 1,000 tokens. An unknown model falls back to the
 * `default` price (zero unless an operator configures one), so a new model can
 * never spend money silently.
 *
 * The class writes only metadata: token counts and cost. It never sees prompt
 * text or a health value.
 */
final class CostAccountant
{
    /**
     * @return array{input_tokens: ?int, output_tokens: ?int, cost_minor: int, requests_today: int, remaining: int}
     */
    public function settle(Workspace $workspace, User $actor, LlmResponse $response): array
    {
        $inputTokens = $response->inputTokens === null ? null : max(0, $response->inputTokens);
        $outputTokens = $response->outputTokens === null ? null : max(0, $response->outputTokens);
        $costMinor = $this->costMinor($response->model, $inputTokens, $outputTokens);

        $dayLimit = max(0, (int) config('ai.limits.per_user_day', 20));

        $counter = AiUsageCounter::query()
            ->where('workspace_id', $workspace->id)
            ->where('actor_id', $actor->id)
            ->whereDate('day', now()->toDateString())
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            return [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_minor' => $costMinor,
                'requests_today' => 0,
                'remaining' => $dayLimit,
            ];
        }

        $counter->tokens += ($inputTokens ?? 0) + ($outputTokens ?? 0);
        $counter->cost_minor += $costMinor;
        $counter->save();

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_minor' => $costMinor,
            'requests_today' => $counter->requests,
            'remaining' => max(0, $dayLimit - $counter->requests),
        ];
    }

    /**
     * Cost in minor units for one completion. Rounded to the nearest minor
     * unit; a zero-priced or unknown model costs zero.
     */
    public function costMinor(?string $model, ?int $inputTokens, ?int $outputTokens): int
    {
        $rates = $this->ratesFor($model);

        $inputRate = (int) ($rates['input'] ?? 0);
        $outputRate = (int) ($rates['output'] ?? 0);

        $cost = (($inputTokens ?? 0) / 1000) * max(0, $inputRate)
            + (($outputTokens ?? 0) / 1000) * max(0, $outputRate);

        return max(0, (int) round($cost));
    }

    /**
     * Monthly cost total for a workspace (a query, not a new ledger). Read
     * under the caller's tenant/workspace RLS context.
     */
    public function monthlyWorkspaceTotal(string $workspaceId): int
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        return (int) AiUsageCounter::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('day', [$monthStart, $monthEnd])
            ->sum('cost_minor');
    }

    /**
     * @return array{input?: int|float|string, output?: int|float|string}
     */
    private function ratesFor(?string $model): array
    {
        $pricing = config('ai.pricing', []);

        if (! is_array($pricing)) {
            return [];
        }

        if (is_string($model) && isset($pricing[$model]) && is_array($pricing[$model])) {
            return $pricing[$model];
        }

        $default = $pricing['default'] ?? [];

        return is_array($default) ? $default : [];
    }
}
