<?php

namespace App\Modules\HealthRecord\Domain;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Domain validation for a condition record.
 *
 * Duplicated at the HTTP boundary as form validation, but enforced here as
 * well so imports/jobs cannot create states the API would reject.
 */
final class ConditionRules
{
    /** @var list<string> */
    public const STATUSES = ['active', 'resolved'];

    public const NOTES_MAX_LENGTH = 5000;

    /**
     * @param  array<string, mixed>  $input
     * @return array{code: ?string, name: string, diagnosed_at: ?Carbon, status: string, notes: ?string}
     */
    public static function forCreate(array $input): array
    {
        return self::validate([
            'code' => $input['code'] ?? null,
            'name' => $input['name'] ?? null,
            'diagnosed_at' => $input['diagnosed_at'] ?? null,
            'status' => $input['status'] ?? 'active',
            'notes' => $input['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{code: ?string, name: string, diagnosed_at: ?Carbon, status: string, notes: ?string}
     */
    public static function forUpdate(object $condition, array $input): array
    {
        $state = [
            'code' => $condition->code,
            'name' => $condition->name,
            'diagnosed_at' => $condition->diagnosed_at,
            'status' => $condition->status,
            'notes' => $condition->notes,
        ];

        foreach (['code', 'name', 'diagnosed_at', 'status', 'notes'] as $field) {
            if (array_key_exists($field, $input)) {
                $state[$field] = $input[$field];
            }
        }

        return self::validate($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{code: ?string, name: string, diagnosed_at: ?Carbon, status: string, notes: ?string}
     */
    private static function validate(array $state): array
    {
        $name = self::nullableString($state['name']);

        if ($name === null || mb_strlen($name) > 255) {
            self::fail('name', 'A condition name is required.');
        }

        $code = self::nullableString($state['code']);

        if ($code !== null && mb_strlen($code) > 16) {
            self::fail('code', 'The condition code is too long.');
        }

        $status = is_string($state['status']) ? $state['status'] : 'active';

        if (! in_array($status, self::STATUSES, true)) {
            self::fail('status', 'The condition status is not supported.');
        }

        $notes = self::nullableString($state['notes']);

        if ($notes !== null && mb_strlen($notes) > self::NOTES_MAX_LENGTH) {
            self::fail('notes', 'The notes are too long.');
        }

        return [
            'code' => $code,
            'name' => $name,
            'diagnosed_at' => self::date($state['diagnosed_at'], 'diagnosed_at'),
            'status' => $status,
            'notes' => $notes,
        ];
    }

    private static function date(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);
        } catch (Throwable) {
            self::fail($field, 'The date is not valid.');
        }

        return $date->startOfDay();
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return never
     */
    private static function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
