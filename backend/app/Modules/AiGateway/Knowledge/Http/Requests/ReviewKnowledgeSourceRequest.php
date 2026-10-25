<?php

namespace App\Modules\AiGateway\Knowledge\Http\Requests;

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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reviewer' => ['required', 'string', 'min:3', 'max:100'],
            'build' => ['sometimes', 'boolean'],
        ];
    }
}
