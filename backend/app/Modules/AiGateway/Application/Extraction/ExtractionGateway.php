<?php

namespace App\Modules\AiGateway\Application\Extraction;

use App\Models\User;
use App\Modules\AiGateway\Application\AiQuotaService;
use App\Modules\AiGateway\Application\AiRequestRecorder;
use App\Modules\AiGateway\Application\CostAccountant;
use App\Modules\AiGateway\Application\ModelResolver;
use App\Modules\AiGateway\Application\PolicyAndConsentVerifier;
use App\Modules\AiGateway\Application\ProviderRouter;
use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\ExtractionResult;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\AiGateway\Domain\PromptImage;
use App\Modules\AiGateway\Domain\ProviderException;
use App\Modules\AiGateway\Models\AiRequest;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * Vision extraction pipeline (TASK P-3, AIG-007).
 *
 * A caregiver supplies a document id and a kind. The gateway:
 *  1. refuses before any work when AI or vision is unavailable (F-09);
 *  2. re-checks `ai.health_context.use` plus `ai_processing` consent;
 *  3. resolves the document inside the route's workspace/profile and requires
 *     an available, unscanned-or-clean image of an allowed type;
 *  4. reserves the per-user quota (`.ai/08-AI-GATEWAY.md` section 4) before the
 *     image leaves the process, then settles tokens/cost into the daily ledger;
 *  5. sends the bytes as a `data:` URL (never a signed/public URL) inside a
 *     guarded prompt;
 *  6. validates the JSON with the health-domain rules and returns a draft
 *     with warnings — never a health-row write.
 *
 * Besides one metadata-only `ai_requests` row per attempt, the only write is
 * the daily `ai_usage_counters` ledger (requests/tokens/cost). No health row is
 * ever written (AIG-007): the draft exists only in the response.
 */
final class ExtractionGateway
{
    public function __construct(
        private readonly PolicyAndConsentVerifier $policy,
        private readonly AiQuotaService $quota,
        private readonly ModelResolver $models,
        private readonly ExtractionPromptBuilder $prompts,
        private readonly ExtractionValidator $validator,
        private readonly AiRequestRecorder $recorder,
        private readonly CostAccountant $cost,
        private readonly LlmProvider $provider,
    ) {}

    public function extract(
        Workspace $workspace,
        HealthProfile $profile,
        User $actor,
        string $documentId,
        string $kind,
        string $locale,
    ): ExtractionResult {
        $started = hrtime(true);

        $request = new GatewayRequest(
            workspace: $workspace,
            profile: $profile,
            actor: $actor,
            periodFrom: CarbonImmutable::now()->subDay()->startOfDay(),
            periodTo: CarbonImmutable::now()->endOfDay(),
            purpose: AiRequest::PURPOSE_OCR,
            locale: $locale,
        );

        if (! (bool) config('ai.enabled', false)) {
            $this->refuse($request, 'ai_disabled', AiRequest::STATUS_DISABLED, $started);

            return ExtractionResult::failed('ai_disabled', 503);
        }

        $model = $this->models->forPurpose(AiRequest::PURPOSE_OCR) ?? '';

        // Fail closed before any document read or provider call. A text-only
        // model must never receive an image.
        if (! $this->visionCapable($model)) {
            $this->refuse($request, 'ai_vision_unavailable', AiRequest::STATUS_REFUSED, $started, $model);

            return ExtractionResult::failed('ai_vision_unavailable', 503);
        }

        $decision = $this->policy->verify($request);

        if (! $decision->allowed) {
            $this->refuse(
                $request,
                (string) $decision->reasonCode,
                AiRequest::STATUS_REFUSED,
                $started,
                $model,
            );

            return ExtractionResult::failed((string) $decision->reasonCode, $decision->httpStatus);
        }

        [$document, $documentError, $documentStatus] = $this->resolveDocument($workspace, $profile, $documentId);

        if (! $document instanceof MedicalDocument) {
            $this->refuse(
                $request,
                (string) $documentError,
                AiRequest::STATUS_REFUSED,
                $started,
                $model,
            );

            return ExtractionResult::failed((string) $documentError, (int) $documentStatus);
        }

        $bytes = $this->readBytes($document);

        if ($bytes === null) {
            $this->refuse($request, 'document_unreadable', AiRequest::STATUS_FAILED, $started, $model);

            return ExtractionResult::failed('document_unreadable', 503);
        }

        $maxBytes = max(1, (int) config('ai.extraction.max_bytes', 5 * 1024 * 1024));

        if (strlen($bytes) > $maxBytes) {
            $this->refuse($request, 'document_too_large', AiRequest::STATUS_REFUSED, $started, $model);

            return ExtractionResult::failed('document_too_large', 422);
        }

        // Quota (`.ai/08-AI-GATEWAY.md` section 4). It is checked after
        // authorization and after the document preconditions — a denied caller
        // learns nothing and an invalid request spends nothing — but always
        // before the image or any provider call leaves this process. A failed
        // provider call still counts, exactly like the summary path.
        $reservation = $this->quota->reserve($workspace, $actor);

        if (! $reservation['allowed']) {
            $this->refuse($request, 'ai_quota_exceeded', AiRequest::STATUS_REFUSED, $started, $model);

            return ExtractionResult::failed('ai_quota_exceeded', 429);
        }

        $documentRefs = [[
            'type' => 'medical_document',
            'id' => $document->id,
        ]];

        $envelope = $this->prompts->build(
            $kind,
            $locale,
            new PromptImage((string) $document->mime_type, $bytes),
        );

        $contextHash = $envelope->hash();

        try {
            $response = $this->complete($envelope);
        } catch (ProviderException $exception) {
            $this->recordProviderFailure(
                $request,
                $exception->errorCode,
                $documentRefs,
                $contextHash,
                $started,
                $model,
            );

            return ExtractionResult::failed($exception->errorCode, $exception->httpStatus);
        } catch (Throwable $exception) {
            // Never report the original exception: a transport error can carry
            // a request body. Log the class only (N-02).
            report(new \RuntimeException('AI extraction failure: '.$exception::class));

            $this->recordProviderFailure(
                $request,
                'provider_error',
                $documentRefs,
                $contextHash,
                $started,
                $model,
            );

            return ExtractionResult::failed('ai_provider_unavailable', 503);
        }

        try {
            $fields = $this->decode($response->content);
        } catch (ProviderException $exception) {
            $this->recordProviderFailure(
                $request,
                $exception->errorCode,
                $documentRefs,
                $contextHash,
                $started,
                $model,
                $response->model ?? $model,
            );

            return ExtractionResult::failed($exception->errorCode, $exception->httpStatus);
        }

        $validated = $this->validator->validate($kind, $fields);

        $modelUsed = $response->model ?? $model;

        // Cost accounting updates the same daily ledger the quota reserved, so
        // the audit row and the usage counter cannot disagree.
        $accounting = $this->cost->settle($workspace, $actor, $response);

        $usage = [
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_minor' => $accounting['cost_minor'],
        ];

        $this->recorder->record(
            request: $request,
            status: AiRequest::STATUS_SUCCEEDED,
            errorCode: in_array('injection_neutralised', $validated['warnings'], true)
                ? 'injection_neutralised'
                : null,
            recordRefs: $documentRefs,
            contextHash: $contextHash,
            latencyMs: $this->latency($started),
            usage: $usage,
            provider: $this->providerName(),
            model: $modelUsed,
        );

        return ExtractionResult::draft(
            $kind,
            $validated['fields'],
            $validated['warnings'],
            $validated['confidence'],
            $document->id,
            $modelUsed,
        );
    }

    /**
     * @return array{0: ?MedicalDocument, 1: ?string, 2: ?int}
     */
    private function resolveDocument(
        Workspace $workspace,
        HealthProfile $profile,
        string $documentId,
    ): array {
        // `withTrashed()` is deliberate: a deleted document must be a 422
        // validation outcome, not a silent 404 that hides the reason.
        $document = MedicalDocument::withTrashed()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->whereKey($documentId)
            ->first();

        if (! $document instanceof MedicalDocument) {
            return [null, 'document_not_found', 422];
        }

        if ($document->profile_id !== $profile->id) {
            // A document that belongs to another profile is never readable
            // here, even when the caller can see the profile.
            return [null, 'document_not_found', 422];
        }

        if ($document->trashed() || $document->status !== MedicalDocument::STATUS_AVAILABLE) {
            return [null, 'document_not_available', 422];
        }

        if (! in_array($document->scan_status, [MedicalDocument::SCAN_CLEAN, MedicalDocument::SCAN_SKIPPED], true)) {
            return [null, 'document_not_available', 422];
        }

        $allowedMime = array_values(array_filter(
            (array) config('ai.extraction.allowed_mime', []),
            static fn (mixed $mime): bool => is_string($mime) && $mime !== '',
        ));

        if (! in_array($document->mime_type, $allowedMime, true)) {
            return [null, 'document_not_image', 422];
        }

        return [$document, null, null];
    }

    private function readBytes(MedicalDocument $document): ?string
    {
        try {
            $bytes = Storage::disk((string) $document->storage_disk)
                ->get((string) $document->storage_path);
        } catch (Throwable) {
            $bytes = null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function complete(PromptEnvelope $envelope): LlmResponse
    {
        if ($this->provider instanceof ProviderRouter) {
            return $this->provider->completeFor(AiRequest::PURPOSE_OCR, $envelope);
        }

        return $this->provider->complete($envelope);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $content): array
    {
        $trimmed = trim($content);

        // The prompt asks for one object. A code fence, a list, a scalar or a
        // truncated body is a provider contract failure, not a draft.
        if ($trimmed === '' || ! str_starts_with($trimmed, '{')) {
            throw ProviderException::invalidResponse();
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ProviderException::invalidResponse();
        }

        if (! is_array($decoded)) {
            throw ProviderException::invalidResponse();
        }

        return $decoded;
    }

    private function visionCapable(string $model): bool
    {
        if ($model === '') {
            return false;
        }

        $models = array_values(array_filter(
            (array) config('ai.vision_models', []),
            static fn (mixed $id): bool => is_string($id) && trim($id) !== '',
        ));

        return in_array($model, array_map(static fn (string $id): string => trim($id), $models), true);
    }

    private function recordProviderFailure(
        GatewayRequest $request,
        string $errorCode,
        array $recordRefs,
        string $contextHash,
        int $started,
        string $model,
        ?string $responseModel = null,
    ): void {
        $this->recorder->record(
            request: $request,
            status: AiRequest::STATUS_FAILED,
            errorCode: $errorCode,
            recordRefs: $recordRefs,
            contextHash: $contextHash,
            latencyMs: $this->latency($started),
            provider: $this->providerName(),
            model: $responseModel ?? $model,
        );
    }

    private function refuse(
        GatewayRequest $request,
        string $errorCode,
        string $status,
        int $started,
        ?string $model = null,
    ): void {
        $this->recorder->record(
            request: $request,
            status: $status,
            errorCode: $errorCode,
            recordRefs: [],
            contextHash: $this->recorder->emptyContextHash(),
            latencyMs: $this->latency($started),
            model: $model,
        );
    }

    private function providerName(): ?string
    {
        return $this->provider instanceof ProviderRouter
            ? $this->provider->activeProviderName()
            : null;
    }

    private function latency(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
