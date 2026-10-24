<?php

namespace App\Modules\AiGateway\Knowledge\Application;

use App\Models\User;
use App\Modules\AiGateway\Application\AiQuotaService;
use App\Modules\AiGateway\Application\AiRequestRecorder;
use App\Modules\AiGateway\Application\CostAccountant;
use App\Modules\AiGateway\Application\InjectionGuard;
use App\Modules\AiGateway\Application\IntakeClassifier;
use App\Modules\AiGateway\Application\ModelResolver;
use App\Modules\AiGateway\Application\OutputValidator;
use App\Modules\AiGateway\Application\PiiScrubber;
use App\Modules\AiGateway\Application\PolicyAndConsentVerifier;
use App\Modules\AiGateway\Application\ProviderRouter;
use App\Modules\AiGateway\Contracts\LlmProvider;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\InvalidOutputException;
use App\Modules\AiGateway\Domain\LlmResponse;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\AiGateway\Domain\ProviderException;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeAnswerResult;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeUnavailableException;
use App\Modules\AiGateway\Models\AiRequest;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Answers a general health question from the reviewed knowledge corpus
 * (TASK G4-KB, `.ai/08-AI-GATEWAY.md`).
 *
 * The pipeline is the same policy/consent gate and audit shape as the summary
 * path, with three differences:
 *
 *  1. the context is a set of public reference chunks, not the patient's record;
 *  2. the question itself is sent to the provider, wrapped as an untrusted
 *     block and scrubbed like any user text;
 *  3. an answer that cannot cite the corpus is refused: when retrieval finds
 *     nothing, the model is not called at all.
 *
 * The AI never writes health data and never diagnoses; the prompt and the
 * intake classifier enforce that, and the response always carries citations
 * and a general-information disclaimer.
 */
final class KnowledgeGateway
{
    public function __construct(
        private readonly PolicyAndConsentVerifier $policy,
        private readonly IntakeClassifier $intake,
        private readonly KnowledgeRetriever $retriever,
        private readonly KnowledgePromptBuilder $prompts,
        private readonly PiiScrubber $scrubber,
        private readonly InjectionGuard $injection,
        private readonly OutputValidator $validator,
        private readonly AiQuotaService $quota,
        private readonly AiRequestRecorder $recorder,
        private readonly CostAccountant $cost,
        private readonly LlmProvider $provider,
        private readonly ModelResolver $models,
    ) {}

    public function answer(
        Workspace $workspace,
        HealthProfile $profile,
        User $actor,
        string $question,
        string $locale,
    ): KnowledgeAnswerResult {
        $started = hrtime(true);

        $request = new GatewayRequest(
            workspace: $workspace,
            profile: $profile,
            actor: $actor,
            periodFrom: CarbonImmutable::now()->subDay()->startOfDay(),
            periodTo: CarbonImmutable::now()->endOfDay(),
            purpose: AiRequest::PURPOSE_KNOWLEDGE,
            locale: $locale,
            requestText: $question,
        );

        if (! (bool) config('ai.enabled', false)) {
            $this->refuse($request, 'ai_disabled', AiRequest::STATUS_DISABLED, $started);

            return KnowledgeAnswerResult::failed('ai_disabled', 503);
        }

        $decision = $this->policy->verify($request);

        if (! $decision->allowed) {
            $this->refuse($request, (string) $decision->reasonCode, AiRequest::STATUS_REFUSED, $started);

            return KnowledgeAnswerResult::failed((string) $decision->reasonCode, $decision->httpStatus);
        }

        $intake = $this->intake->classify($question);

        if ($intake->shouldRefuse()) {
            $this->refuse($request, $intake->action, AiRequest::STATUS_REFUSED, $started);

            return KnowledgeAnswerResult::failed($intake->action, 422, $intake->message);
        }

        try {
            $sources = $this->retriever->search($question, (int) config('knowledge.top_k', 5));
        } catch (KnowledgeUnavailableException) {
            $this->refuse($request, 'knowledge_unavailable', AiRequest::STATUS_FAILED, $started);

            return KnowledgeAnswerResult::failed(
                'knowledge_unavailable',
                503,
                (string) config('knowledge.unavailable_message'),
            );
        }

        if ($sources === []) {
            // No corpus match means no provider call: an answer without a
            // reference would be exactly the hallucination the gate exists to
            // prevent (F-09).
            $this->refuse($request, 'knowledge_not_found', AiRequest::STATUS_REFUSED, $started);

            return KnowledgeAnswerResult::failed(
                'knowledge_not_found',
                422,
                (string) config('knowledge.no_answer_message'),
            );
        }

        $reservation = $this->quota->reserve($workspace, $actor);

        if (! $reservation['allowed']) {
            $this->refuse($request, 'ai_quota_exceeded', AiRequest::STATUS_REFUSED, $started);

            return KnowledgeAnswerResult::failed('ai_quota_exceeded', 429);
        }

        $scrubbed = $this->scrubber->scrub(['question' => $question], []);
        $cleanQuestion = $scrubbed->sections['question'] ?? $question;
        $cleanQuestion = is_string($cleanQuestion) && trim($cleanQuestion) !== '' ? $cleanQuestion : $question;

        $envelope = $this->prompts->build($cleanQuestion, $sources, $locale);
        $inspection = $this->injection->inspect($envelope);
        $contextHash = $inspection->envelope->hash();

        try {
            $response = $this->complete($inspection->envelope);
        } catch (ProviderException $exception) {
            $this->recordFailure($request, $exception->errorCode, $contextHash, $started);

            return KnowledgeAnswerResult::failed($exception->errorCode, $exception->httpStatus);
        } catch (Throwable $exception) {
            // Never report the original exception: a transport error can carry
            // a request body (N-02).
            report(new \RuntimeException('Knowledge answer failure: '.$exception::class));

            $this->recordFailure($request, 'provider_error', $contextHash, $started);

            return KnowledgeAnswerResult::failed('ai_provider_unavailable', 503);
        }

        $model = $this->modelUsed($response);

        $groundingContext = [
            '_references' => array_map(
                static fn (array $source): string => (string) $source['text'],
                $sources,
            ),
        ];

        try {
            $output = $this->validator->validate(
                $response,
                $groundingContext,
                (string) config('knowledge.disclaimer'),
            );
        } catch (InvalidOutputException $exception) {
            $this->recordFailure($request, $exception->getMessage(), $contextHash, $started, $model);

            return KnowledgeAnswerResult::failed('ai_output_invalid', 503);
        }

        $publicSources = $this->withLabels($sources);
        $answer = rtrim($output->content);
        $answer .= "\n\n".$this->citationBlock($publicSources);

        if ($this->containsUnreviewed($sources)) {
            $answer .= "\n\n".(string) config('knowledge.unreviewed_warning');
        }

        $accounting = $this->cost->settle($workspace, $actor, $response);
        $usage = [
            'requests_today' => $accounting['requests_today'],
            'remaining' => $accounting['remaining'],
        ];

        $this->recorder->record(
            request: $request,
            status: AiRequest::STATUS_SUCCEEDED,
            errorCode: $inspection->detected() ? 'injection_neutralised' : null,
            recordRefs: $this->recordRefs($sources),
            contextHash: $contextHash,
            latencyMs: $this->latency($started),
            usage: [
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_minor' => $accounting['cost_minor'],
            ],
            provider: $this->providerName(),
            model: $model,
        );

        return KnowledgeAnswerResult::success(
            $answer,
            $publicSources,
            (string) config('knowledge.disclaimer'),
            $usage,
            $model,
        );
    }

    private function complete(PromptEnvelope $envelope): LlmResponse
    {
        if ($this->provider instanceof ProviderRouter) {
            return $this->provider->completeFor(AiRequest::PURPOSE_KNOWLEDGE, $envelope);
        }

        return $this->provider->complete($envelope);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function withLabels(array $sources): array
    {
        $letters = range('A', 'Z');
        $public = [];
        $seen = [];

        foreach ($sources as $source) {
            $id = (string) $source['document_id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $public[] = [
                'label' => $letters[count($public)] ?? (string) (count($public) + 1),
                'id' => $id,
                'title' => (string) $source['title'],
                'publisher' => (string) $source['publisher'],
                'url' => (string) $source['url'],
                'published_at' => $source['published_at'],
                'fetched_at' => (string) $source['fetched_at'],
                'review_status' => (string) $source['review_status'],
            ];
        }

        return $public;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function citationBlock(array $sources): string
    {
        $lines = ['แหล่งอ้างอิง:'];

        foreach ($sources as $source) {
            $date = $source['published_at'] ?: $source['fetched_at'];
            $lines[] = sprintf(
                '[%s] %s — %s — %s (ข้อมูล ณ %s)',
                $source['label'],
                $source['title'],
                $source['publisher'],
                $source['url'],
                $date,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array{type: string, id: string}>
     */
    private function recordRefs(array $sources): array
    {
        $refs = [];
        $seen = [];

        foreach ($sources as $source) {
            $id = (string) $source['document_id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $refs[] = ['type' => 'knowledge_document', 'id' => $id];
        }

        return $refs;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function containsUnreviewed(array $sources): bool
    {
        foreach ($sources as $source) {
            if (($source['review_status'] ?? '') !== 'reviewed') {
                return true;
            }
        }

        return false;
    }

    private function modelUsed(LlmResponse $response): string
    {
        $model = $response->model;

        if (is_string($model) && trim($model) !== '') {
            return $model;
        }

        return $this->models->forPurpose(AiRequest::PURPOSE_KNOWLEDGE) ?? 'unknown';
    }

    private function recordFailure(
        GatewayRequest $request,
        string $errorCode,
        string $contextHash,
        int $started,
        ?string $model = null,
    ): void {
        $this->recorder->record(
            request: $request,
            status: AiRequest::STATUS_FAILED,
            errorCode: $errorCode,
            recordRefs: [],
            contextHash: $contextHash,
            latencyMs: $this->latency($started),
            provider: $this->providerName(),
            model: $model,
        );
    }

    private function refuse(
        GatewayRequest $request,
        string $errorCode,
        string $status,
        int $started,
    ): void {
        $this->recorder->record(
            request: $request,
            status: $status,
            errorCode: $errorCode,
            recordRefs: [],
            contextHash: $this->recorder->emptyContextHash(),
            latencyMs: $this->latency($started),
            model: $this->models->forPurpose(AiRequest::PURPOSE_KNOWLEDGE),
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
