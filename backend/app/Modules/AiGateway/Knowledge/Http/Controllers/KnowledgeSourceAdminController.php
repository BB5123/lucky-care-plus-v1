<?php

namespace App\Modules\AiGateway\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Modules\AiGateway\Knowledge\Application\KnowledgeSourceService;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceInvalidException;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeSourceNotFoundException;
use App\Modules\AiGateway\Knowledge\Http\Requests\ReviewKnowledgeSourceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

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
}
