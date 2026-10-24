<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\FetchedContext;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\GuardrailOutcome;

/**
 * Steps 3–5 of the gateway pipeline (`.ai/08-AI-GATEWAY.md` section 2):
 * scrubbing → guardrail envelope + injection guard → provider call and output
 * validation.
 *
 * The sequence lives in one class so the live request path and the offline
 * evaluation (`ai:evaluate`) cannot drift: both call this method with the same
 * collaborators and the same provider contract. The only thing the evaluation
 * changes is where the `FetchedContext` comes from and which provider is
 * passed in — never a guard.
 */
final class GuardrailPipeline
{
    public function __construct(
        private readonly PiiScrubber $scrubber,
        private readonly InjectionGuard $injection,
        private readonly GuardrailPrompting $guardrails,
        private readonly OutputValidator $validator,
    ) {}

    public function process(
        FetchedContext $fetched,
        GatewayRequest $request,
        LlmProvider $provider,
    ): GuardrailOutcome {
        // [3] PII scrubbing and pseudonymization.
        $scrubbed = $this->scrubber->scrub($fetched->sections, $fetched->personNames);

        // [4] Clinical guardrails, untrusted-block envelope and injection guard.
        $envelope = $this->guardrails->build($scrubbed, $request);
        $injection = $this->injection->inspect($envelope);

        // [5] Provider call, then output validation (disclaimer, PII,
        // grounding and safety advice).
        $response = $provider->complete($injection->envelope);

        // The requested period is authorised context: it is sent to the model
        // in `structured_context`, so a summary that names it must not be
        // rejected as ungrounded. Values outside both the sections and this
        // period are still a hallucination.
        $groundingContext = $scrubbed->sections;
        $groundingContext['_period'] = [
            'from' => $request->periodFrom->toDateString(),
            'to' => $request->periodTo->toDateString(),
        ];

        $output = $this->validator->validate($response, $groundingContext);

        return new GuardrailOutcome(
            $scrubbed,
            $injection->envelope,
            $injection,
            $response,
            $output,
        );
    }
}
