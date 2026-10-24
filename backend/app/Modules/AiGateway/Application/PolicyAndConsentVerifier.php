<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\GatewayDecision;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\ResourceRef;
use App\Modules\Consent\Models\ConsentRecord;

/**
 * Step 1 — Policy & Consent Verifier (`.ai/08-AI-GATEWAY.md` section 2).
 *
 * Both conditions are mandatory and are re-evaluated on every attempt:
 *
 *   1. the actor holds `ai.health_context.use` for this profile, which the
 *      Authorizer maps to the `ai_context` scope and the `ai_processing`
 *      purpose; and
 *   2. an active `ai_processing` consent row exists for the profile.
 *
 * There is deliberately no internal-caller bypass (F-06, F-09): reading a
 * measurement does not imply permission to send it to a model, and a missing
 * consent never falls through to a fetch.
 */
final class PolicyAndConsentVerifier
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function verify(GatewayRequest $request): GatewayDecision
    {
        $decision = $this->authorizer->authorize(
            $request->actor,
            'ai.health_context.use',
            ResourceRef::forProfile($request->workspace->id, $request->profile->id),
            'ai_processing',
        );

        if (! $decision->allowed()) {
            return match ($decision->code()) {
                'authorization_unavailable' => GatewayDecision::refuse('authorization_unavailable', 503),
                'no_consent' => GatewayDecision::refuse('no_consent', 403),
                default => GatewayDecision::refuse('forbidden', 403),
            };
        }

        // Defence in depth: the Authorizer already checks this purpose because
        // `ai.health_context.use` is consent-gated, but the gateway states the
        // requirement explicitly so a future permission-map change cannot turn
        // it into an accidental bypass.
        $hasConsent = ConsentRecord::query()
            ->where('profile_id', $request->profile->id)
            ->where('purpose', 'ai_processing')
            ->whereNull('withdrawn_at')
            ->exists();

        if (! $hasConsent) {
            return GatewayDecision::refuse('no_consent', 403);
        }

        return GatewayDecision::allow();
    }
}
