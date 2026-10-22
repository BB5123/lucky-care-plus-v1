<?php

namespace App\Modules\HealthRecord\Domain;

use App\Modules\HealthRecord\Models\HealthMeasurement;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Domain validation for a measurement.
 *
 * This lives outside the FormRequests on purpose: a queue job, import or
 * contract test must be unable to persist a reading the HTTP layer would have
 * rejected. Messages never echo the submitted value (N-02).
 */
final class MeasurementRules
{
    /** @var list<string> */
    public const CONTEXTS = [
        'fasting',
        'postprandial',
        'random',
        'morning',
        'evening',
    ];

    /** @var list<string> */
    public const SOURCES = [
        'manual',
        'device',
        'care_task',
        'import',
    ];

    public const NOTE_MAX_LENGTH = 500;

    private const EARLIEST_RECORDED_AT = '1900-01-01 00:00:00';

    private const FUTURE_TOLERANCE_MINUTES = 5;

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, value: float, secondary_value: ?float, context: ?string, note: ?string, recorded_at: Carbon, source: string}
     */
    public static function forCreate(array $input): array
    {
        return self::validate([
            'type' => $input['type'] ?? null,
            'value' => $input['value'] ?? null,
            'secondary_value' => $input['secondary_value'] ?? null,
            'context' => $input['context'] ?? null,
            'note' => $input['note'] ?? null,
            'recorded_at' => $input['recorded_at'] ?? null,
            'source' => $input['source'] ?? 'manual',
        ]);
    }

    /**
     * Merge a correction over the stored row and re-validate the whole state,
     * so removing a required companion value (e.g. the diastolic reading) is
     * rejected just like an invalid create.
     *
     * @param  array<string, mixed>  $input
     * @return array{type: string, value: float, secondary_value: ?float, context: ?string, note: ?string, recorded_at: Carbon, source: string}
     */
    public static function forUpdate(HealthMeasurement $measurement, array $input): array
    {
        $state = [
            'type' => $measurement->type,
            'value' => $measurement->value,
            'secondary_value' => $measurement->secondary_value,
            'context' => $measurement->context,
            'note' => $measurement->note,
            'recorded_at' => $measurement->recorded_at,
            'source' => $measurement->source,
        ];

        foreach (['value', 'secondary_value', 'context', 'note', 'recorded_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $state[$field] = $input[$field];
            }
        }

        return self::validate($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{type: string, value: float, secondary_value: ?float, context: ?string, note: ?string, recorded_at: Carbon, source: string}
     */
    private static function validate(array $state): array
    {
        $type = is_string($state['type']) ? MeasurementType::tryFrom($state['type']) : null;

        if ($type === null) {
            self::fail('type', 'The measurement type is not supported.');
        }

        $value = self::numeric($state['value'], 'value', 'A numeric value is required.');

        if ($value < $type->minimum() || $value > $type->maximum()) {
            self::fail('value', 'The value is outside the permitted range for this measurement type.');
        }

        $secondary = $state['secondary_value'] === null || $state['secondary_value'] === ''
            ? null
            : self::numeric($state['secondary_value'], 'secondary_value', 'The secondary value must be numeric.');

        if ($type->requiresSecondaryValue()) {
            if ($secondary === null) {
                self::fail('secondary_value', 'The secondary value is required for this measurement type.');
            }

            if ($secondary < 30.0 || $secondary > 200.0) {
                self::fail('secondary_value', 'The secondary value is outside the permitted range.');
            }

            if ($value <= $secondary) {
                self::fail('value', 'The primary value must be greater than the secondary value.');
            }
        } elseif ($secondary !== null) {
            self::fail('secondary_value', 'This measurement type does not accept a secondary value.');
        }

        $context = self::nullableString($state['context']);

        if ($type->requiresContext() && $context === null) {
            self::fail('context', 'A context is required for this measurement type.');
        }

        if ($context !== null && ! in_array($context, self::CONTEXTS, true)) {
            self::fail('context', 'The context is not supported.');
        }

        $note = self::nullableString($state['note']);

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            self::fail('note', 'The note may not be longer than 500 characters.');
        }

        $source = is_string($state['source']) ? $state['source'] : 'manual';

        if (! in_array($source, self::SOURCES, true)) {
            self::fail('source', 'The source is not supported.');
        }

        return [
            'type' => $type->value,
            'value' => $value,
            'secondary_value' => $secondary,
            'context' => $context,
            'note' => $note,
            'recorded_at' => self::timestamp($state['recorded_at']),
            'source' => $source,
        ];
    }

    private static function numeric(mixed $value, string $field, string $message): float
    {
        if (! is_numeric($value)) {
            self::fail($field, $message);
        }

        return (float) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function timestamp(mixed $value): Carbon
    {
        try {
            $at = $value instanceof DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse(is_string($value) && $value !== '' ? $value : 'now');
        } catch (Throwable) {
            self::fail('recorded_at', 'The recorded time is not a valid timestamp.');
        }

        $now = now();

        if ($at->greaterThan($now->copy()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            self::fail('recorded_at', 'The recorded time may not be in the future.');
        }

        if ($at->lessThan(Carbon::parse(self::EARLIEST_RECORDED_AT))) {
            self::fail('recorded_at', 'The recorded time is before the supported range.');
        }

        return $at;
    }

    /**
     * @return never
     */
    private static function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
