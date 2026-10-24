<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\GatewayResult;
use App\Modules\AiGateway\Domain\InvalidOutputException;
use App\Modules\AiGateway\Domain\PiiScrubException;
use App\Modules\AiGateway\Models\AiRequest;
use Throwable;

/**
 * The five-step pipeline (`.ai/08-AI-GATEWAY.md` section 2).
 *
 * Steps run in order and cannot be reordered: policy/consent (1), scoped fetch
 * (2), scrubbing (3), guardrail envelope (4), provider call and output
 * validation (5). A provider is only reachable after steps 1–3 (AIG-001).
 *
 * Every attempt that reaches this service writes exactly one `ai_requests`
 * row, including refusals and failures, and that row never contains prompt
 * text, model output or a health value.
 */
final class ContextGateway
{
    public function __construct(
        private readonly PolicyAndConsentVerifier $policy,
        private readonly ScopedDataFetcher $fetcher,
        private readonly PiiScrubber $scrubber,
        private readonly GuardrailPrompting $guardrails,
        private readonly OutputValidator $validator,
        private readonly AiQuotaService $quota,
        private readonly AiRequestRecorder $recorder,
        private readonly LlmProvider $provider,
    ) {}

    public function run(GatewayRequest $request): GatewayResult
    {
        $started = hrtime(true);
        $emptyContextHash = $this->recorder->emptyContextHash();

        // Feature flag first: before any fetch, before any provider. The row is
        // still written so an operator can see that the endpoint was called
        // while the gate was closed.
        if (! (bool) config('ai.enabled', false)) {
            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_DISABLED,
                errorCode: 'ai_disabled',
                recordRefs: [],
                contextHash: $emptyContextHash,
                latencyMs: $this->latency($started),
            );

            return GatewayResult::disabled();
        }

        // [1] Policy & Consent.
        $decision = $this->policy->verify($request);

        if (! $decision->allowed) {
            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_REFUSED,
                errorCode: $decision->reasonCode,
                recordRefs: [],
                contextHash: $emptyContextHash,
                latencyMs: $this->latency($started),
            );

            return GatewayResult::refused((string) $decision->reasonCode, $decision->httpStatus);
        }

        // Quota is checked after authorization (a denied caller learns nothing)
        // and before any data is fetched.
        $reservation = $this->quota->reserve($request->workspace, $request->actor);

        if (! $reservation['allowed']) {
            $usage = [
                'requests_today' => $reservation['requests_today'],
                'remaining' => $reservation['remaining'],
            ];

            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_REFUSED,
                errorCode: 'ai_quota_exceeded',
                recordRefs: [],
                contextHash: $emptyContextHash,
                latencyMs: $this->latency($started),
            );

            return GatewayResult::refused('ai_quota_exceeded', 429, $usage);
        }

        $usage = [
            'requests_today' => $reservation['requests_today'],
            'remaining' => $reservation['remaining'],
        ];

        $fetched = null;
        $contextHash = $emptyContextHash;

        try {
            // [2] Scoped data fetch.
            $fetched = $this->fetcher->fetch($request);

            // [3] PII scrubbing and pseudonymization.
            $scrubbed = $this->scrubber->scrub($fetched->sections, $fetched->personNames);

            // [4] Clinical guardrails and untrusted-block envelope.
            $envelope = $this->guardrails->build($scrubbed, $request);
            $contextHash = $envelope->hash();

            // [5] Provider call, then validation.
            $response = $this->provider->complete($envelope);
            $summary = $this->validator->validate($response);

            $latencyMs = $this->latency($started);
            $usage = $this->quota->observe(
                $request->workspace,
                $request->actor,
                $response->inputTokens,
                $response->outputTokens,
                null,
            );

            $this->validator->recordSuccess(
                $request,
                $response,
                $fetched->recordRefs,
                $contextHash,
                $latencyMs,
            );

            return GatewayResult::success(
                $summary,
                (string) config('ai.disclaimer'),
                $fetched->recordRefs,
                $usage,
                $response->model ?? (string) config('ai.model', 'null-provider-v1'),
            );
        } catch (PiiScrubException $e) {
            // A rule could not be applied confidently: do not send the context
            // (AIG-003).
            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_REFUSED,
                errorCode: 'scrub_'.$e->getMessage(),
                recordRefs: $fetched?->recordRefs ?? [],
                contextHash: $contextHash,
                latencyMs: $this->latency($started),
            );

            return GatewayResult::refused('ai_context_refused', 403, $usage);
        } catch (InvalidOutputException $e) {
            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_FAILED,
                errorCode: $e->getMessage(),
                recordRefs: $fetched?->recordRefs ?? [],
                contextHash: $contextHash,
                latencyMs: $this->latency($started),
            );

            return GatewayResult::failed('ai_output_invalid', 503, $usage);
        } catch (Throwable $e) {
            // Do not report the original exception: a provider/database error
            // can carry a payload. Log the class only (N-02).
            report(new \RuntimeException('AI gateway failure: '.$e::class));

            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_FAILED,
                errorCode: 'provider_error',
                recordRefs: $fetched?->recordRefs ?? [],
                contextHash: $contextHash,
                latencyMs: $this->latency($started),
            );

            return GatewayResult::failed('ai_provider_unavailable', 503, $usage);
        }
    }

    private function latency(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
