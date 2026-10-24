<?php

namespace App\Modules\AiGateway\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\AiGateway\Knowledge\Application\KnowledgeGateway;
use App\Modules\AiGateway\Knowledge\Domain\KnowledgeAnswerResult;
use App\Modules\AiGateway\Knowledge\Http\Requests\AskKnowledgeRequest;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/workspaces/{workspace}/profiles/{profile}/ai/knowledge-answers
 *
 * The route has already proven authentication, workspace membership,
 * `ai.report.create` and `ai_processing` consent. The gateway re-checks the
 * policy, refuses diagnosis/prescribing/emergency questions through the intake
 * classifier, answers only from the referenced corpus and appends citations.
 */
final class KnowledgeAnswerController extends Controller
{
    public function __construct(private readonly KnowledgeGateway $gateway) {}

    public function __invoke(AskKnowledgeRequest $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            return ProblemDetails::make(
                $request,
                503,
                'authorization_unavailable',
                'Authorization unavailable',
                'ไม่สามารถดำเนินการได้ในขณะนี้',
            );
        }

        $profileId = $request->route('profile');

        $profile = is_string($profileId)
            ? HealthProfile::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($profileId)
                ->first()
            : null;

        if (! $profile instanceof HealthProfile) {
            return ProblemDetails::make(
                $request,
                404,
                'out_of_scope',
                'Resource not accessible',
                'ไม่พบข้อมูลที่ร้องขอ',
            );
        }

        $actor = $request->user();

        if (! $actor instanceof User) {
            return ProblemDetails::make(
                $request,
                401,
                'unauthenticated',
                'Authentication required',
                'กรุณายืนยันตัวตนก่อนเข้าถึงข้อมูล',
            );
        }

        $result = $this->gateway->answer(
            $workspace,
            $profile,
            $actor,
            (string) $request->validated('question'),
            $request->locale(),
        );

        if ($result->succeeded) {
            return response()->json(
                [
                    'data' => [
                        'answer' => $result->answer,
                        'sources' => $result->sources,
                        'disclaimer' => $result->disclaimer,
                        'model' => $result->model,
                        'usage' => $result->usage,
                    ],
                ],
                200,
                [
                    'Cache-Control' => 'no-store',
                    'Referrer-Policy' => 'no-referrer',
                ],
            );
        }

        return $this->problem($request, $result);
    }

    private function problem(Request $request, KnowledgeAnswerResult $result): JsonResponse
    {
        $code = $result->errorCode ?? 'ai_knowledge_failed';
        $status = $result->httpStatus > 0 ? $result->httpStatus : 503;
        $message = $result->message !== null && trim($result->message) !== '' ? $result->message : null;

        [$title, $detail, $errors] = match ($code) {
            'ai_disabled' => [
                'AI disabled',
                'ฟีเจอร์ AI ยังไม่เปิดใช้งาน',
                [],
            ],
            'knowledge_unavailable' => [
                'Knowledge base unavailable',
                $message ?? 'ฐานความรู้สุขภาพยังไม่พร้อมใช้งานในขณะนี้',
                [],
            ],
            'knowledge_not_found' => [
                'Validation failed',
                $message ?? 'ไม่พบข้อมูลที่เกี่ยวข้องในฐานความรู้สุขภาพ',
                ['question' => [$message ?? 'ไม่พบข้อมูลที่เกี่ยวข้องในฐานความรู้สุขภาพ']],
            ],
            'no_consent' => [
                'Consent required',
                'ไม่ได้รับความยินยอมสำหรับการประมวลผลด้วย AI',
                [],
            ],
            'forbidden' => [
                'Forbidden',
                'ไม่ได้รับอนุญาตให้ใช้ข้อมูลนี้กับ AI',
                [],
            ],
            'authorization_unavailable' => [
                'Authorization unavailable',
                'ไม่สามารถตรวจสอบสิทธิ์ได้ในขณะนี้',
                [],
            ],
            'ai_quota_exceeded' => [
                'AI quota exceeded',
                'คุณใช้งาน AI ครบจำนวนที่กำหนดในวันนี้แล้ว กรุณาลองใหม่พรุ่งนี้',
                [],
            ],
            'refuse_diagnosis', 'refuse_prescribing', 'refuse_emergency' => [
                'Request refused',
                $message ?? 'ระบบไม่สามารถให้คำแนะนำทางการแพทย์เฉพาะบุคคลได้ กรุณาปรึกษาแพทย์',
                [],
            ],
            'provider_refused' => [
                'AI provider refused the request',
                (string) config('ai.provider_messages.provider_refused', 'ระบบ AI ไม่สามารถให้คำตอบสำหรับคำขอนี้ได้'),
                [],
            ],
            'provider_rejected' => [
                'AI provider rejected the request',
                (string) config('ai.provider_messages.provider_rejected', 'ระบบ AI ไม่สามารถประมวลผลคำขอนี้ได้'),
                [],
            ],
            default => [
                'AI knowledge answer failed',
                'ระบบ AI ไม่สามารถตอบคำถามได้ในขณะนี้ กรุณาลองใหม่ภายหลัง',
                [],
            ],
        };

        return ProblemDetails::make($request, $status, $code, $title, $detail, [], $errors);
    }
}
