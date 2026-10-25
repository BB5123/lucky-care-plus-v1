<?php

namespace App\Modules\AiGateway\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Modules\AiGateway\Knowledge\Application\KnowledgeSourceService;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeCorpusReadonlyException;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceInvalidException;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceNotFoundException;
use App\Modules\AiGateway\Knowledge\Http\Requests\ReviewKnowledgeSourceRequest;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Authz\Authorizer;
use App\Modules\Workspace\Models\Tenant;
use App\Support\Database\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Platform knowledge-corpus administration (CR-005 / baseline v1.4.0).
 *
 * The routes live outside `/workspaces/{workspace}`: the corpus is public
 * platform data with no tenant or workspace. `authorize.platform` has already
 * proven `knowledge.source.read` / `knowledge.source.review` against the
 * actor's explicit platform-admin flag before this controller runs.
 */
final class KnowledgeSourceAdminController extends Controller
{
    public function __construct(private readonly SecurityAuditLogger $audit) {}

    public function index(Request $request, KnowledgeSourceService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:unreviewed,reviewed'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json(
            ['data' => $service->list($validated['status'] ?? null, $validated['q'] ?? null)],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    public function review(
        ReviewKnowledgeSourceRequest $request,
        string $id,
        KnowledgeSourceService $service,
    ): JsonResponse {
        $reviewer = (string) $request->validated('reviewer');
        $build = $request->has('build') ? $request->boolean('build') : true;

        try {
            $outcome = $service->review($id, $reviewer, $build);
        } catch (KnowledgeSourceNotFoundException) {
            return ProblemDetails::make(
                $request,
                404,
                'out_of_scope',
                'Resource not accessible',
                'ไม่พบข้อมูลที่ร้องขอ',
            );
        } catch (KnowledgeSourceInvalidException $exception) {
            return ProblemDetails::make(
                $request,
                422,
                'validation',
                'Validation failed',
                'ข้อมูลที่ส่งมาไม่ถูกต้อง',
                errors: ['source' => $exception->errors()],
            );
        } catch (KnowledgeCorpusReadonlyException $exception) {
            report($exception);
            $this->auditCorpusReadonly($request, $id);

            return ProblemDetails::make(
                $request,
                503,
                'knowledge_corpus_readonly',
                'Knowledge corpus is read-only',
                (string) config('ai.provider_messages.knowledge_corpus_readonly'),
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return ProblemDetails::make(
                $request,
                503,
                'knowledge_unavailable',
                'Knowledge base unavailable',
                'ไม่สามารถสร้างฐานความรู้ได้ในขณะนี้',
            );
        }

        return response()->json(
            ['data' => $outcome['source']],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * One failed security-audit row for a review that could not be persisted.
     *
     * The authorization middleware already wrote the `allow` decision; this is
     * the outcome of the mutation itself. Platform data has no workspace, so
     * the row is chained into the tenant directory with `workspace_id = null`,
     * exactly like {@see Authorizer::authorizePlatform()}.
     * Only the slug and the reviewer are metadata - never the source text
     * (N-02).
     */
    private function auditCorpusReadonly(Request $request, string $id): void
    {
        try {
            $tenantId = Tenant::query()
                ->orderByRaw("case when slug = 'default' then 0 else 1 end")
                ->orderBy('id')
                ->value('id');

            if (! is_string($tenantId) || $tenantId === '') {
                throw new RuntimeException(
                    'Platform audit requires a tenant directory row (F-11).'
                );
            }

            $metadata = ['scope' => 'platform'];

            if ($id !== '') {
                $metadata['source_id'] = $id;
            }

            $reviewer = $request->input('reviewer');

            if (is_string($reviewer)) {
                $reviewer = trim($reviewer);

                if (mb_strlen($reviewer) >= 3 && mb_strlen($reviewer) <= 100) {
                    $metadata['reviewer'] = $reviewer;
                }
            }

            $actor = $request->user();

            TenantContext::run($tenantId, '', function () use ($actor, $metadata): void {
                $this->audit->record([
                    'actor_id' => $actor?->id,
                    'actor_type' => $actor === null ? 'anonymous' : 'user',
                    'action' => 'knowledge.source.review',
                    'target_type' => 'platform',
                    'target_id' => null,
                    'result' => 'fail',
                    'reason_code' => 'corpus_readonly',
                    'metadata' => $metadata,
                ]);
            });
        } catch (Throwable $exception) {
            // The mutation already failed; a broken audit append must not turn
            // the typed 503 into an unhandled 500.
            report($exception);
        }
    }
}
