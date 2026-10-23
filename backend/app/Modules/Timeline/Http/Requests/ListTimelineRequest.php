<?php

namespace App\Modules\Timeline\Http\Requests;

use App\Modules\Timeline\Domain\TimelineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Query contract for the timeline page.
 *
 * Parameters are whitelist-only (`.ai/07 §1`). The profile is a route
 * parameter and is never accepted here, so a caller cannot point the read at
 * another profile through the query string (N-01).
 */
final class ListTimelineRequest extends FormRequest
{
    private const ALLOWED = ['from', 'to', 'types', 'cursor', 'limit'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $unknown = array_values(array_diff(array_keys($this->query()), self::ALLOWED));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filter' => ['The requested filter is not supported.'],
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'types' => ['nullable', 'string'],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Comma-separated type filter, validated against the closed vocabulary.
     * An absent or empty value means "every type".
     *
     * @return list<string>
     */
    public function types(): array
    {
        $raw = $this->validated()['types'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return TimelineType::values();
        }

        $types = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $type): bool => $type !== '',
        )));

        $unknown = array_values(array_diff($types, TimelineType::values()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'types' => ['The requested timeline type is not supported.'],
            ]);
        }

        return $types === [] ? TimelineType::values() : $types;
    }
}
