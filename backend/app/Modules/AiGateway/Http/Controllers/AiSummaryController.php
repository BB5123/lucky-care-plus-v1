<?php

namespace App\Modules\AiGateway\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\AiGateway\Application\ContextGateway;
use App\Modules\AiGateway\Domain\GatewayRequest;
use App\Modules\AiGateway\Domain\GatewayResult;
use App\Modules\AiGateway\Http\Requests\CreateAiSummaryRequest;
use App\Modules\AiGateway\Models\AiRequest;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/workspaces/{workspace}/profiles/{profile}/ai/summaries
 *
 * The route middleware has already proven authentication, workspace
 * membership, `ai.report.create` and `ai_processing` consent. The controller
 * resolves profile and actor from server-side route/session context only
 * (N-01) and maps each gateway outcome to the contract envelope.
 */
final class AiSummaryController extends Controller
{
    public function __construct(private readonly ContextGateway $gateway) {}

    public function __invoke(CreateAiSummaryRequest $request): JsonResponse
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

        $window = $request->window();
        $question = $request->validated('question');

        $result = $this->gateway->run(new GatewayRequest(
            workspace: $workspace,
            profile: $profile,
            actor: $actor,
            periodFrom: $window['from'],
            periodTo: $window['to'],
            purpose: AiRequest::PURPOSE_SUMMARY,
            locale: (string) config('ai.locale', 'th'),
            requestText: is_string($question) ? $question : null,
        ));

        if ($result->status === 'succeeded') {
            return response()->json(
                [
                    'data' => [
                        'summary' => $result->summary,
                        'disclaimer' => $result->disclaimer,
                        'record_refs' => $result->recordRefs,
                        'usage' => [
                            'requests_today' => $result->usage['requests_today'] ?? 0,
                            'remaining' => $result->usage['remaining'] ?? 0,
                        ],
                        'model' => $result->model,
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

    private function problem(Request $request, GatewayResult $result): JsonResponse
    {
        $code = $result->errorCode ?? 'ai_request_failed';

        $headers = $result->httpStatus === 429
            ? ['Retry-After' => '60']
            : [];

        [$title, $detail] = match ($code) {
            'ai_disabled' => ['AI disabled', 'ฟีเจอร์ AI ยังไม่เปิดใช้งาน'],
            'ai_quota_exceeded' => ['AI quota exceeded', 'คุณใช้โควต้า AI ครบแล้ว กรุณาลองใหม่ภายหลัง'],
            'no_consent' => ['Consent required', 'ไม่ได้รับความยินยอมสำหรับการประมวลผลด้วย AI'],
            'forbidden' => ['Forbidden', 'ไม่ได้รับอนุญาตให้ใช้ข้อมูลนี้กับ AI'],
            'authorization_unavailable' => ['Authorization unavailable', 'ไม่สามารถตรวจสอบสิทธิ์ได้ในขณะนี้'],
            'refuse_diagnosis', 'refuse_prescribing', 'refuse_emergency' => [
                'AI request refused',
                (string) config('ai.intake.messages.'.$code, 'ไม่สามารถดำเนินการกับคำขอนี้ได้'),
            ],
            'provider_timeout', 'provider_unavailable', 'provider_invalid_response', 'ai_provider_unavailable' => [
                'AI provider unavailable',
                (string) config('ai.provider_messages.'.$code, 'ระบบ AI ไม่พร้อมใช้งานในขณะนี้ กรุณาลองใหม่ภายหลัง'),
            ],
            'provider_rejected' => [
                'AI provider rejected the request',
                (string) config('ai.provider_messages.provider_rejected', 'ระบบ AI ไม่สามารถประมวลผลคำขอนี้ได้ กรุณาลองใหม่ภายหลัง'),
            ],
            'provider_refused' => [
                'AI provider refused the request',
                (string) config('ai.provider_messages.provider_refused', 'ระบบ AI ไม่สามารถให้คำตอบสำหรับคำขอนี้ได้ กรุณาปรึกษาแพทย์หรือเจ้าหน้าที่สาธารณสุข'),
            ],
            'pii_in_output' => [
                'AI output rejected',
                (string) config('ai.provider_messages.pii_in_output', 'ระบบ AI ไม่สามารถให้คำตอบที่ปลอดภัยได้ กรุณาลองใหม่ภายหลัง'),
            ],
            default => ['AI request failed', 'ไม่สามารถดำเนินการกับ AI ได้ในขณะนี้'],
        };

        return ProblemDetails::make(
            $request,
            $result->httpStatus,
            $code,
            $title,
            $detail,
            $headers,
        );
    }
}
