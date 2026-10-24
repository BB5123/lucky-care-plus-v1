<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Models\AiRequest;

/**
 * The single writer for `ai_requests` (AIG-002 / AIG-007).
 *
 * Every path (disabled, refused, failed, succeeded) goes through here so the
 * shape is identical and no prompt text, model output or health value can
 * accidentally be added to the row. `record_refs` is normalised to the frozen
 * `[{type, id}]` shape before the insert.
 */
final class AiRequestRecorder
{
    /**
     * @param  list<array{type: string, id: string}>  $recordRefs
     * @param  array{input_tokens?: ?int, output_tokens?: ?int, cost_minor?: ?int}  $usage
     */
    public function record(
        GatewayRequest $request,
        string $status,
        ?string $errorCode,
        array $recordRefs,
        string $contextHash,
        int $latencyMs,
        array $usage = [],
        ?string $provider = null,
        ?string $model = null,
    ): AiRequest {
        $refs = [];

        foreach ($recordRefs as $ref) {
            if (! is_array($ref) || ! isset($ref['type'], $ref['id'])) {
                continue;
            }

            $refs[] = [
                'type' => (string) $ref['type'],
                'id' => (string) $ref['id'],
            ];
        }

        $contextHash = preg_match('/^[0-9a-f]{64}$/', $contextHash) === 1
            ? $contextHash
            : hash('sha256', '');

        return AiRequest::query()->create([
            'tenant_id' => $request->workspace->tenant_id,
            'workspace_id' => $request->workspace->id,
            'profile_id' => $request->profile->id,
            'actor_id' => $request->actor->id,
            'purpose' => $request->purpose,
            'provider' => $provider ?? $this->providerName(),
            'model' => $model ?? (string) config('ai.model', 'null-provider-v1'),
            'status' => $status,
            'error_code' => $errorCode,
            'latency_ms' => max(0, $latencyMs),
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'cost_minor' => $usage['cost_minor'] ?? null,
            'record_refs' => $refs,
            'context_hash' => $contextHash,
        ]);
    }

    public function emptyContextHash(): string
    {
        return hash('sha256', '');
    }

    public function providerName(): string
    {
        $provider = config('ai.provider');

        if (is_string($provider) && $provider !== '') {
            $parts = explode('\\', $provider);

            return (string) end($parts);
        }

        return 'null';
    }
}
