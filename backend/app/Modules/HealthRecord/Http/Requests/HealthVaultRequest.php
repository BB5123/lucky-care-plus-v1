<?php

namespace App\Modules\HealthRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Shared base for Health Vault requests.
 *
 * Authorization is not decided here: every route carries `authorize.permission`
 * so the single Authorizer pipeline owns the decision (backend/AGENTS.md #1).
 */
abstract class HealthVaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Filtering is whitelist-only (`.ai/07-API-CONTRACT.md §1`); an unknown
     * query key is a 422, never silently ignored.
     *
     * @param  list<string>  $allowed
     */
    protected function rejectUnknownQueryKeys(array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($this->query()), $allowed));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filter' => ['The requested filter is not supported.'],
            ]);
        }
    }
}
