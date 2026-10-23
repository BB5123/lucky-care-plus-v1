<?php

namespace App\Modules\CareCrm\Http\Requests;

use App\Modules\CareCrm\Models\EscalationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Whole-ladder replacement for a workspace (P2.2).
 *
 * Shape rules:
 *  - at most three levels, each 1–3;
 *  - levels unique and contiguous from 1 (so `[1,3]` is rejected rather than
 *    silently leaving a hole in the ladder);
 *  - `after_minutes` strictly increasing with the level;
 *  - every role is one of the frozen membership roles, otherwise 422.
 *
 * Workspace identity comes from the trusted route, never the body (N-01).
 */
class ReplaceEscalationPoliciesRequest extends FormRequest
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
            'policies' => ['present', 'array', 'max:3'],
            'policies.*.level' => ['required', 'integer', 'between:1,3', 'distinct'],
            'policies.*.after_minutes' => ['required', 'integer', 'min:5', 'max:525600'],
            'policies.*.notify_assignee' => ['sometimes', 'boolean'],
            'policies.*.notify_roles' => ['sometimes', 'array', 'max:8'],
            'policies.*.notify_roles.*' => ['string', Rule::in(EscalationPolicy::ROLES)],
            'policies.*.action' => ['sometimes', Rule::in(EscalationPolicy::ACTIONS)],
            'policies.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var list<array<string, mixed>> $policies */
                $policies = $this->input('policies', []);

                if ($policies === []) {
                    return;
                }

                usort($policies, fn (array $a, array $b): int => (int) $a['level'] <=> (int) $b['level']);

                $levels = array_map(fn (array $policy): int => (int) $policy['level'], $policies);

                if ($levels !== range(1, count($levels))) {
                    $validator->errors()->add(
                        'policies',
                        'Escalation levels must be unique and contiguous from 1.',
                    );

                    return;
                }

                $previous = null;

                foreach ($policies as $policy) {
                    $after = (int) $policy['after_minutes'];

                    if ($previous !== null && $after <= $previous) {
                        $validator->errors()->add(
                            'policies',
                            'after_minutes must be strictly increasing with the level.',
                        );

                        return;
                    }

                    $previous = $after;
                }
            },
        ];
    }
}
