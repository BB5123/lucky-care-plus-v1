<?php

namespace App\Modules\HealthRecord\Domain;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Domain validation for an allergy record.
 */
final class AllergyRules
{
    /** @var list<string> */
    public const SEVERITIES = ['mild', 'moderate', 'severe'];

    public const REACTION_MAX_LENGTH = 500;

    /**
     * @param  array<string, mixed>  $input
     * @return array{substance: string, reaction: ?string, severity: string, noted_at: ?Carbon}
     */
    public static function forCreate(array $input): array
    {
        return self::validate([
            'substance' => $input['substance'] ?? null,
            'reaction' => $input['reaction'] ?? null,
            'severity' => $input['severity'] ?? null,
            'noted_at' => $input['noted_at'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{substance: string, reaction: ?string, severity: string, noted_at: ?Carbon}
     */
    public static function forUpdate(object $allergy, array $input): array
    {
        $state = [
            'substance' => $allergy->substance,
            'reaction' => $allergy->reaction,
            'severity' => $allergy->severity,
            'noted_at' => $allergy->noted_at,
        ];

        foreach (['substance', 'reaction', 'severity', 'noted_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $state[$field] = $input[$field];
            }
        }

        return self::validate($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{substance: string, reaction: ?string, severity: string, noted_at: ?Carbon}
     */
    private static function validate(array $state): array
    {
        $substance = self::nullableString($state['substance']);

        if ($substance === null || mb_strlen($substance) > 255) {
            self::fail('substance', 'An allergy substance is required.');
        }

        $reaction = self::nullableString($state['reaction']);

        if ($reaction !== null && mb_strlen($reaction) > self::REACTION_MAX_LENGTH) {
            self::fail('reaction', 'The reaction description is too long.');
        }

        $severity = is_string($state['severity']) ? $state['severity'] : '';

        if (! in_array($severity, self::SEVERITIES, true)) {
            self::fail('severity', 'The allergy severity is not supported.');
        }

        return [
            'substance' => $substance,
            'reaction' => $reaction,
            'severity' => $severity,
            'noted_at' => self::date($state['noted_at']),
        ];
    }

    private static function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);
        } catch (Throwable) {
            self::fail('noted_at', 'The date is not valid.');
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
