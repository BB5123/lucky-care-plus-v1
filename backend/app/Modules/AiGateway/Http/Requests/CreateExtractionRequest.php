<?php

namespace App\Modules\AiGateway\Http\Requests;

use App\Modules\AiGateway\Application\Extraction\ExtractionPromptBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body validation for POST .../ai/extractions (TASK P-3).
 *
 * Only identifiers and a closed `kind` are accepted from the client. The
 * workspace, profile, actor and consent are resolved server-side (N-01); the
 * document is re-scoped against the route profile by the gateway.
 */
final class CreateExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is the route's `authorize.permission` middleware.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_id' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'string', Rule::in(ExtractionPromptBuilder::KINDS)],
            // A locale is interpolated into the system prompt, so it is kept
            // to a language/region tag rather than arbitrary text.
            'locale' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z]{2}(?:[-_][A-Za-z]{2})?$/'],
        ];
    }

    public function locale(): string
    {
        $locale = $this->validated('locale');

        return is_string($locale) && trim($locale) !== ''
            ? trim($locale)
            : (string) config('ai.locale', 'th');
    }
}
