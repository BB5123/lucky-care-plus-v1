<?php

namespace App\Modules\HealthRecord\Domain;

use App\Modules\HealthRecord\Models\MedicationPlan;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Domain validation for a medication plan.
 *
 * Kept outside the FormRequests on purpose: a queue job, import or contract
 * test must not be able to persist a plan the HTTP layer would have rejected.
 * Error messages never echo a submitted value (N-02).
 *
 * A plan is the *schedule*; the dose events live in `MedicationRecord`. A
 * "taken" event is self-reported and is not clinical proof of ingestion
 * (`.ai/05-CARE-COORDINATION.md` §3).
 */
final class MedicationPlanRules
{
    /** @var list<string> */
    public const FORMS = ['tablet', 'capsule', 'liquid', 'injection', 'patch', 'inhaler', 'other'];

    /** @var list<string> */
    public const ROUTES = ['oral', 'topical', 'injection', 'inhalation', 'other'];

    /** @var list<string> */
    public const SOURCES = ['manual', 'care_task', 'import'];

    /** @var list<string> */
    public const STATUSES = [MedicationPlan::STATUS_ACTIVE, MedicationPlan::STATUS_DISCONTINUED];

    public const NAME_MAX_LENGTH = 255;

    public const DOSAGE_MAX_LENGTH = 100;

    public const PRESCRIBER_MAX_LENGTH = 255;

    public const INSTRUCTIONS_MAX_LENGTH = 5000;

    public const MIN_DOSES_PER_DAY = 1;

    public const MAX_DOSES_PER_DAY = 12;

    public const MIN_DISCONTINUE_REASON_LENGTH = 5;

    public const DISCONTINUE_REASON_MAX_LENGTH = 1000;

    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, dosage: ?string, form: string, route: ?string, doses_per_day: int, schedule_times: list<string>, starts_on: Carbon, ends_on: ?Carbon, prescribed_by: ?string, instructions: ?string, source: string}
     */
    public static function forCreate(array $input): array
    {
        return self::validate([
            'name' => $input['name'] ?? null,
            'dosage' => $input['dosage'] ?? null,
            'form' => $input['form'] ?? null,
            'route' => $input['route'] ?? null,
            'doses_per_day' => $input['doses_per_day'] ?? null,
            'schedule_times' => $input['schedule_times'] ?? null,
            'starts_on' => $input['starts_on'] ?? null,
            'ends_on' => $input['ends_on'] ?? null,
            'prescribed_by' => $input['prescribed_by'] ?? null,
            'instructions' => $input['instructions'] ?? null,
            'source' => $input['source'] ?? 'manual',
        ]);
    }

    /**
     * Merge a correction over the stored plan and re-validate the whole state,
     * so reducing `doses_per_day` without touching `schedule_times` (or the
     * reverse) is rejected just like an invalid create.
     *
     * `status` is intentionally absent: only the dedicated discontinue action
     * may close a plan, because that is the only path that records a reason.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, dosage: ?string, form: string, route: ?string, doses_per_day: int, schedule_times: list<string>, starts_on: Carbon, ends_on: ?Carbon, prescribed_by: ?string, instructions: ?string, source: string}
     */
    public static function forUpdate(MedicationPlan $plan, array $input): array
    {
        $state = [
            'name' => $plan->name,
            'dosage' => $plan->dosage,
            'form' => $plan->form,
            'route' => $plan->route,
            'doses_per_day' => $plan->doses_per_day,
            'schedule_times' => $plan->schedule_times,
            'starts_on' => $plan->starts_on,
            'ends_on' => $plan->ends_on,
            'prescribed_by' => $plan->prescribed_by,
            'instructions' => $plan->instructions,
            'source' => $plan->source,
        ];

        foreach ([
            'name',
            'dosage',
            'form',
            'route',
            'doses_per_day',
            'schedule_times',
            'starts_on',
            'ends_on',
            'prescribed_by',
            'instructions',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $state[$field] = $input[$field];
            }
        }

        return self::validate($state);
    }

    /**
     * Discontinuation always needs a human-readable reason (`.ai/02 §3`: the
     * C8 aggregate invariant is "discontinue must have a reason").
     */
    public static function discontinueReason(mixed $raw): string
    {
        $reason = self::nullableString($raw);

        if ($reason === null || mb_strlen($reason) < self::MIN_DISCONTINUE_REASON_LENGTH) {
            self::fail('reason', 'A discontinuation reason is required.');
        }

        if (mb_strlen($reason) > self::DISCONTINUE_REASON_MAX_LENGTH) {
            self::fail('reason', 'The discontinuation reason is too long.');
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{name: string, dosage: ?string, form: string, route: ?string, doses_per_day: int, schedule_times: list<string>, starts_on: Carbon, ends_on: ?Carbon, prescribed_by: ?string, instructions: ?string, source: string}
     */
    private static function validate(array $state): array
    {
        $name = self::nullableString($state['name']);

        if ($name === null) {
            self::fail('name', 'A medication name is required.');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            self::fail('name', 'The medication name is too long.');
        }

        $dosage = self::nullableString($state['dosage']);

        if ($dosage !== null && mb_strlen($dosage) > self::DOSAGE_MAX_LENGTH) {
            self::fail('dosage', 'The dosage is too long.');
        }

        $form = self::nullableString($state['form']);

        if ($form === null || ! in_array($form, self::FORMS, true)) {
            self::fail('form', 'The medication form is not supported.');
        }

        $route = self::nullableString($state['route']);

        if ($route !== null && ! in_array($route, self::ROUTES, true)) {
            self::fail('route', 'The medication route is not supported.');
        }

        $dosesPerDay = self::integer($state['doses_per_day'], 'doses_per_day', 'The number of daily doses is required.');

        if ($dosesPerDay < self::MIN_DOSES_PER_DAY || $dosesPerDay > self::MAX_DOSES_PER_DAY) {
            self::fail('doses_per_day', 'The number of daily doses is outside the permitted range.');
        }

        $scheduleTimes = self::scheduleTimes($state['schedule_times'], $dosesPerDay);

        $startsOn = self::date($state['starts_on'], 'starts_on');

        if ($startsOn === null) {
            self::fail('starts_on', 'A start date is required.');
        }

        $endsOn = self::date($state['ends_on'], 'ends_on');

        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            self::fail('ends_on', 'The end date may not be before the start date.');
        }

        $prescribedBy = self::nullableString($state['prescribed_by']);

        if ($prescribedBy !== null && mb_strlen($prescribedBy) > self::PRESCRIBER_MAX_LENGTH) {
            self::fail('prescribed_by', 'The prescriber name is too long.');
        }

        $instructions = self::nullableString($state['instructions']);

        if ($instructions !== null && mb_strlen($instructions) > self::INSTRUCTIONS_MAX_LENGTH) {
            self::fail('instructions', 'The instructions are too long.');
        }

        $source = is_string($state['source']) ? $state['source'] : 'manual';

        if (! in_array($source, self::SOURCES, true)) {
            self::fail('source', 'The source is not supported.');
        }

        return [
            'name' => $name,
            'dosage' => $dosage,
            'form' => $form,
            'route' => $route,
            'doses_per_day' => $dosesPerDay,
            'schedule_times' => $scheduleTimes,
            'starts_on' => $startsOn->startOfDay(),
            'ends_on' => $endsOn?->startOfDay(),
            'prescribed_by' => $prescribedBy,
            'instructions' => $instructions,
            'source' => $source,
        ];
    }

    /**
     * `schedule_times` must contain exactly one valid, unique `HH:MM` slot per
     * daily dose.
     *
     * @return list<string>
     */
    private static function scheduleTimes(mixed $value, int $dosesPerDay): array
    {
        if (! is_array($value) || $value === []) {
            self::fail('schedule_times', 'A schedule time is required for every daily dose.');
        }

        $times = [];

        foreach ($value as $slot) {
            $time = is_string($slot) ? trim($slot) : null;

            if ($time === null || preg_match(self::TIME_PATTERN, $time) !== 1) {
                self::fail('schedule_times', 'A schedule time must use the HH:MM format.');
            }

            if (in_array($time, $times, true)) {
                self::fail('schedule_times', 'Schedule times must be unique.');
            }

            $times[] = $time;
        }

        if (count($times) !== $dosesPerDay) {
            self::fail('schedule_times', 'The number of schedule times must match the daily dose count.');
        }

        return $times;
    }

    private static function integer(mixed $value, string $field, string $message): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        self::fail($field, $message);
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
