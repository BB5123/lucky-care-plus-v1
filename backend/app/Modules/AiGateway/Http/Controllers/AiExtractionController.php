<?php

namespace App\Modules\AiGateway\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\AiGateway\Application\Extraction\ExtractionGateway;
use App\Modules\AiGateway\Domain\ExtractionResult;
use App\Modules\AiGateway\Http\Requests\CreateExtractionRequest;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/workspaces/{workspace}/profiles/{profile}/ai/extractions
 *
 * The route has already proven authentication, workspace membership and the
 * `ai.health_context.use` grant (which also requires `ai_processing` consent).
 * This controller resolves profile and actor only from the server-side route
 * and session (N-01) and maps the extraction outcome to the contract envelope.
 *
 * A successful response is always a draft (`requires_confirmation: true`). No
 * medication, measurement or other health row is written here (AIG-007).
 */
final class AiExtractionController extends Controller
{
    public function __construct(private readonly ExtractionGateway $gateway) {}

    public function __invoke(CreateExtractionRequest $request): JsonResponse
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

        $result = $this->gateway->extract(
            $workspace,
            $profile,
            $actor,
            (string) $request->validated('document_id'),
            (string) $request->validated('kind'),
            $request->locale(),
        );

        if ($result->succeeded) {
            return response()->json(
                [
                    'data' => [
                        'kind' => $result->kind,
                        'requires_confirmation' => true,
                        'fields' => (object) $result->fields,
                        'confidence' => $result->confidence,
                        'warnings' => $result->warnings,
                        'document_id' => $result->documentId,
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

    private function problem(Request $request, ExtractionResult $result): JsonResponse
    {
        $code = $result->errorCode ?? 'ai_extraction_failed';
        $status = $result->httpStatus > 0 ? $result->httpStatus : 503;

        // Document preconditions use the API-wide validation envelope; the
        // precise reason stays in `errors.document_id` so the client can bind
        // the message to the control.
        $responseCode = in_array($code, [
            'document_not_found',
            'document_not_available',
            'document_not_image',
            'document_too_large',
        ], true) ? 'validation' : $code;

        [$title, $detail, $errors] = match ($code) {
            'ai_disabled' => [
                'AI disabled',
                'ฟีเจอร์ AI ยังไม่เปิดใช้งาน',
                [],
            ],
            'ai_vision_unavailable' => [
                'AI vision unavailable',
                (string) config('ai.provider_messages.ai_vision_unavailable', 'ระบบ AI ไม่รองรับการอ่านภาพจากเอกสารในขณะนี้'),
                [],
            ],
            'ai_quota_exceeded' => [
                'AI quota exceeded',
                'คุณใช้งาน AI ครบจำนวนที่กำหนดในวันนี้แล้ว กรุณาลองใหม่พรุ่งนี้',
                [],
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
            'document_not_found' => [
                'Validation failed',
                'ไม่พบเอกสารที่ร้องขอ',
                ['document_id' => ['ไม่พบเอกสารที่ร้องขอ']],
            ],
            'document_not_available' => [
                'Validation failed',
                'เอกสารนี้ไม่พร้อมใช้งานสำหรับการอ่านภาพ',
                ['document_id' => ['เอกสารนี้ไม่พร้อมใช้งานสำหรับการอ่านภาพ']],
            ],
            'document_not_image' => [
                'Validation failed',
                'เอกสารนี้ไม่ใช่ภาพที่ระบบรองรับ',
                ['document_id' => ['เอกสารนี้ไม่ใช่ภาพที่ระบบรองรับ']],
            ],
            'document_too_large' => [
                'Validation failed',
                'ไฟล์ภาพมีขนาดใหญ่เกินกว่าที่ระบบรองรับ',
                ['document_id' => ['ไฟล์ภาพมีขนาดใหญ่เกินกว่าที่ระบบรองรับ']],
            ],
            'document_unreadable' => [
                'Document unavailable',
                'ไม่สามารถอ่านเอกสารได้ในขณะนี้',
                [],
            ],
            'provider_timeout', 'provider_unavailable', 'provider_invalid_response', 'ai_provider_unavailable', 'provider_error' => [
                'AI provider unavailable',
                (string) config(
                    'ai.provider_messages.'.$code,
                    'ระบบ AI ไม่พร้อมใช้งานในขณะนี้ กรุณาลองใหม่ภายหลัง',
                ),
                [],
            ],
            'provider_rejected' => [
                'AI provider rejected the request',
                (string) config('ai.provider_messages.provider_rejected', 'ระบบ AI ไม่สามารถประมวลผลคำขอนี้ได้ กรุณาลองใหม่ภายหลัง'),
                [],
            ],
            'provider_refused' => [
                'AI provider refused the request',
                (string) config('ai.provider_messages.provider_refused', 'ระบบ AI ไม่สามารถให้คำตอบสำหรับคำขอนี้ได้ กรุณาปรึกษาแพทย์หรือเจ้าหน้าที่สาธารณสุข'),
                [],
            ],
            default => [
                'AI extraction failed',
                'ไม่สามารถอ่านข้อมูลจากภาพได้ในขณะนี้',
                [],
            ],
        };

        return ProblemDetails::make($request, $status, $responseCode, $title, $detail, [], $errors);
    }
}
