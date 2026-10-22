<?php

namespace App\Modules\HealthRecord\Domain;

use App\Modules\HealthRecord\Models\MedicationRecord;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Domain validation for a reported dose event.
 *
 * CAUTION (`.ai/05-CARE-COORDINATION.md` §3): a `taken` row is a **self-reported**
 * entry. It records that somebody said the dose was taken; it is not medical
 * proof, and no downstream feature may present it as verified administration.
 *
 * A self-reported "taken" is always stored with `source = manual`. Only a
 * trusted system job may pass another source (e.g. a task-driven confirmation).
 */
final class MedicationRecordRules
{
    /** @var list<string> */
    public const STATUSES = [
        MedicationRecord::STATUS_TAKEN,
        MedicationRecord::STATUS_SKIPPED,
        MedicationRecord::STATUS_MISSED,
    ];

    /** @var list<string> */
    public const SOURCES = ['manual', 'care_task', 'import', 'device'];

    public const NOTE_MAX_LENGTH = 2000;

    /** A reported event may only be attached to a dose slot within this window. */
    public const SLOT_TOLERANCE_HOURS = 12;

    /**
     * @param  array<string, mixed>  $input
     * @return array{plan_id: string, status: string, scheduled_for: ?Carbon, recorded_at: Carbon, note: ?string, source: string}
     */
    public static function forCreate(array $input, bool $systemActor = false): array
    {
        return self::validate([
            'plan_id' => $input['plan_id'] ?? null,
            'status' => $input['status'] ?? null,
            'scheduled_for' => $input['scheduled_for'] ?? null,
            'recorded_at' => $input['recorded_at'] ?? null,
            'note' => $input['note'] ?? null,
            'source' => $input['source'] ?? 'manual',
        ], $systemActor);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{plan_id: string, status: string, scheduled_for: ?Carbon, recorded_at: Carbon, note: ?string, source: string}
     */
    public static function forUpdate(MedicationRecord $record, array $input, bool $systemActor = false): array
    {
        $state = [
            'plan_id' => $record->plan_id,
            'status' => $record->status,
            'scheduled_for' => $record->scheduled_for,
            'recorded_at' => $record->recorded_at,
            'note' => $record->note,
            'source' => $record->source,
        ];

        // A correction changes what was reported, never which plan it belongs to.
        foreach (['status', 'scheduled_for', 'recorded_at', 'note', 'source'] as $field) {
            if (array_key_exists($field, $input)) {
                $state[$field] = $input[$field];
            }
        }

        return self::validate($state, $systemActor);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{plan_id: string, status: string, scheduled_for: ?Carbon, recorded_at: Carbon, note: ?string, source: string}
     */
    private static function validate(array $state, bool $systemActor): array
    {
        $planId = is_string($state['plan_id']) ? trim($state['plan_id']) : '';

        if (! Str::isUuid($planId)) {
            self::fail('plan_id', 'A medication plan is required.');
        }

        $status = is_string($state['status']) ? $state['status'] : '';

        if (! in_array($status, self::STATUSES, true)) {
            self::fail('status', 'The medication record status is not supported.');
        }

        $recordedAt = self::timestamp($state['recorded_at'], 'recorded_at') ?? now();

        $scheduledFor = self::timestamp($state['scheduled_for'], 'scheduled_for');

        if ($scheduledFor !== null) {
            $deltaSeconds = abs($scheduledFor->getTimestamp() - $recordedAt->getTimestamp());

            if ($deltaSeconds > self::SLOT_TOLERANCE_HOURS * 3600) {
                self::fail('scheduled_for', 'The dose slot is too far from the recorded time.');
            }
        }

        $note = self::nullableString($state['note']);

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            self::fail('note', 'The note is too long.');
        }

        // A skipped dose without a reason is not actionable for the care team.
        if ($status === MedicationRecord::STATUS_SKIPPED && $note === null) {
            self::fail('note', 'A note is required when a dose is skipped.');
        }

        $source = is_string($state['source']) ? $state['source'] : 'manual';

        if (! in_array($source, self::SOURCES, true)) {
            self::fail('source', 'The source is not supported.');
        }

        // A self-reported "taken" is manual unless a trusted system job says
        // otherwise (see the class docblock).
        if ($status === MedicationRecord::STATUS_TAKEN && ! $systemActor) {
            $source = 'manual';
        }

        return [
            'plan_id' => $planId,
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'recorded_at' => $recordedAt,
            'note' => $note,
            'source' => $source,
        ];
    }

    private static function timestamp(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);
        } catch (Throwable) {
            self::fail($field, 'The time is not a valid timestamp.');
        }
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
