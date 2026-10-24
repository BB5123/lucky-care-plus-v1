<?php

namespace App\Modules\AiGateway\Knowledge\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body for POST .../ai/knowledge-answers (TASK G4-KB).
 *
 * Only the question text and an optional locale are accepted. The workspace,
 * profile, actor and consent are resolved server-side (N-01); the question is
 * untrusted text and is scrubbed, wrapped and injection-guarded before it can
 * reach a provider.
 */
final class AskKnowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:'.max(50, (int) config('knowledge.max_question_chars', 500))],
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
