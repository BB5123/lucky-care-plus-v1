<?php

namespace App\Modules\AiGateway\Knowledge\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/admin/knowledge/sources/{id}/review (CR-005).
 *
 * Authorization is declared on the route (`authorize.platform`), so this
 * request only validates input. `build` defaults to true when omitted.
 */
final class ReviewKnowledgeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reviewer' => [
                'required',
                'string',
                'min:3',
                'max:100',
                // The reviewer name becomes an identity in the audit trail, so it must
                // not be mojibake or a placeholder. A Thai name round-tripped through a
                // shell arrives as "??. ?????" and would otherwise be stored as if it
                // were a person - found while exercising CR-005 against the real stack.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $looksCorrupt = str_contains($value, "\u{FFFD}")
                        || preg_match('/^[\s?\p{P}\p{S}]+$/u', $value) === 1
                        || preg_match('/\p{L}/u', $value) !== 1;

                    if ($looksCorrupt) {
                        $fail('The reviewer name looks corrupted or empty. Enter the reviewer\'s real name.');
                    }
                },
            ],
            'build' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reviewer.required' => 'กรุณาระบุชื่อผู้ตรวจทาน',
            'reviewer.min' => 'ชื่อผู้ตรวจทานต้องมีอย่างน้อย 3 ตัวอักษร',
            'reviewer.max' => 'ชื่อผู้ตรวจทานต้องไม่เกิน 100 ตัวอักษร',
            'build.boolean' => 'ค่าตัวเลือกการสร้างฐานความรู้ใหม่ไม่ถูกต้อง',
        ];
    }
}
