<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\GatewayResult;
use App\Modules\AiGateway\Domain\InvalidOutputException;
use App\Modules\AiGateway\Domain\PiiScrubException;
use App\Modules\AiGateway\Domain\ProviderException;
use App\Modules\AiGateway\Models\AiRequest;
use App\Modules\Audit\Services\SecurityAuditLogger;
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
        private readonly IntakeClassifier $intake,
        private readonly GuardrailPipeline $pipeline,
        private readonly OutputValidator $validator,
        private readonly AiQuotaService $quota,
        private readonly AiRequestRecorder $recorder,
        private readonly LlmProvider $provider,
        private readonly CostAccountant $cost,
        private readonly SecurityAuditLogger $audit,
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

        // [1b] Intake classifier. A request for a diagnosis, a dose change or
        // an emergency is refused before context is fetched and before quota
        // is charged: no data leaves and no provider is reachable (F-06/F-09).
        $intake = $this->intake->classify($request->requestText);

        if ($intake->shouldRefuse()) {
            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_REFUSED,
                errorCode: $intake->action,
                recordRefs: [],
                contextHash: $emptyContextHash,
                latencyMs: $this->latency($started),
            );

            return GatewayResult::refused($intake->action, 422);
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

            // [3]–[5] Scrubbing, guardrails + injection guard, provider call and
            // output validation all run inside the shared guardrail pipeline.
            $outcome = $this->pipeline->process($fetched, $request, $this->provider);

            $contextHash = $outcome->envelope->hash();
            $response = $outcome->response;

            $latencyMs = $this->latency($started);

            // Cost accounting owns token/cost persistence. It updates the same
            // daily counter the quota reserved, so the response usage and the
            // ledger cannot disagree.
            $accounting = $this->cost->settle(
                $request->workspace,
                $request->actor,
                $response,
            );

            $usage = [
                'requests_today' => $accounting['requests_today'],
                'remaining' => $accounting['remaining'],
            ];

            // A neutralised injection still produces a summary, but the event is
            // recorded so an operator can audit it (record which, per G4).
            $this->validator->recordSuccess(
                $request,
                $response,
                $fetched->recordRefs,
                $contextHash,
                $latencyMs,
                $outcome->injection->detected() ? 'injection_neutralized' : null,
                $accounting,
                $this->providerName(),
            );

            return GatewayResult::success(
                $outcome->output->content,
                (string) config('ai.disclaimer'),
                $fetched->recordRefs,
                $usage,
                $response->model ?? (string) config('ai.model', 'null-provider-v1'),
            );
        } catch (ProviderException $e) {
            // The adapter/router has already mapped the failure. Only the
            // stable code is recorded; the body and the transport exception are
            // never stored (N-02).
            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_FAILED,
                errorCode: $e->errorCode,
                recordRefs: $fetched?->recordRefs ?? [],
                contextHash: $contextHash,
                latencyMs: $this->latency($started),
                provider: $this->providerName(),
            );

            return GatewayResult::failed($e->errorCode, $e->httpStatus, $usage);
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
                provider: $this->providerName(),
            );

            return GatewayResult::refused('ai_context_refused', 403, $usage);
        } catch (InvalidOutputException $e) {
            $errorCode = $e->getMessage();

            if ($errorCode === 'output_pii') {
                // The completion itself still contains PII. Discard it, keep
                // only metadata, and put the incident on the security chain.
                $row = $this->recorder->record(
                    request: $request,
                    status: AiRequest::STATUS_FAILED,
                    errorCode: 'pii_in_output',
                    recordRefs: $fetched?->recordRefs ?? [],
                    contextHash: $contextHash,
                    latencyMs: $this->latency($started),
                    provider: $this->providerName(),
                );

                $this->recordPiiIncident($request, $row);

                return GatewayResult::failed('pii_in_output', 503, $usage);
            }

            $this->recorder->record(
                request: $request,
                status: AiRequest::STATUS_FAILED,
                errorCode: $errorCode,
                recordRefs: $fetched?->recordRefs ?? [],
                contextHash: $contextHash,
                latencyMs: $this->latency($started),
                provider: $this->providerName(),
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
                provider: $this->providerName(),
            );

            return GatewayResult::failed('ai_provider_unavailable', 503, $usage);
        }
    }

    /**
     * The provider actually used for this attempt, when the router owns the
     * decision. A test double bound directly to LlmProvider keeps the
     * recorder's configuration-derived default.
     */
    private function providerName(): ?string
    {
        return $this->provider instanceof ProviderRouter
            ? $this->provider->activeProviderName()
            : null;
    }

    /**
     * Append one PHI-free security row. The completion is never passed here;
     * the row names only the request, purpose and reason.
     */
    private function recordPiiIncident(GatewayRequest $request, AiRequest $row): void
    {
        try {
            $this->audit->record([
                'actor_id' => $request->actor->id,
                'actor_type' => 'user',
                'action' => 'ai.output.pii',
                'target_type' => 'ai_request',
                'target_id' => $row->id,
                'result' => 'error',
                'reason_code' => 'pii_in_output',
                'metadata' => ['purpose' => $request->purpose],
            ]);
        } catch (Throwable $e) {
            // The security ledger must not turn a discarded output into a
            // provider error. Record the failure class only (N-02).
            report(new \RuntimeException('AI PII audit failure: '.$e::class));
        }
    }

    private function latency(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
