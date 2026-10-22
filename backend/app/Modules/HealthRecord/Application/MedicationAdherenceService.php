<?php

namespace App\Modules\HealthRecord\Application;

use App\Modules\HealthRecord\Domain\MedicationPlanRules;
use App\Modules\HealthRecord\Domain\MedicationRecordRules;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\MedicationPlan;
use App\Modules\HealthRecord\Models\MedicationRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Adherence for a profile over a rolling window.
 *
 * ## Window
 * The window is the last `days` **completed** calendar days:
 * `[today - days, today)` in the application timezone. Today itself is
 * excluded because its evening doses cannot be judged yet; a plan that started
 * three days ago therefore covers three completed days, not four.
 *
 * ## Expected doses
 * `expected(plan) = doses_per_day × days on which the plan is active inside the
 * window`, where "active" means `starts_on <= day <= ends_on` (no `ends_on`
 * means open-ended). `doses_per_day` is validated to equal
 * `count(schedule_times)` in {@see MedicationPlanRules},
 * so this is exactly the number of scheduled slots the plan expects: the slot
 * list is used for the shape of the day, `doses_per_day` for the count.
 *
 * ## Recorded events
 * A `taken` row is **self-reported, not clinical proof** (`.ai/05` §3). An event
 * is attributed to the day of its `scheduled_for` slot when it has one, and to
 * the day of `recorded_at` otherwise. Only events on an active plan day inside
 * the window are counted, so a stray correction cannot inflate adherence.
 *
 * ## Percent
 * `percent = round(taken / expected * 100, 1)`, or `0.0` when `expected = 0`.
 * `skipped` and `missed` count events explicitly reported with that status; a
 * slot with no event is not counted as taken, which is what `percent` measures.
 */
final class MedicationAdherenceService
{
    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 90;

    /**
     * A record may sit up to 12 h away from its slot (see
     * {@see MedicationRecordRules::SLOT_TOLERANCE_HOURS}),
     * so the query widens the recorded_at range by that margin before the
     * per-day attribution filters the rows.
     */
    private const QUERY_MARGIN_HOURS = 12;

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     days: int,
     *     plans: list<array<string, mixed>>
     * }
     */
    public function compute(HealthProfile $profile, int $days): array
    {
        $days = max(1, min($days, self::MAX_DAYS));

        $windowEnd = now()->startOfDay(); // exclusive
        $windowStart = $windowEnd->copy()->subDays($days);

        /** @var list<string> $dayKeys */
        $dayKeys = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $dayKeys[] = $windowStart->copy()->addDays($offset)->toDateString();
        }

        $plans = MedicationPlan::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($plans as $plan) {
            $planStart = $plan->starts_on->copy()->startOfDay();
            $planEnd = $plan->ends_on?->copy()->startOfDay();

            // Ignore plans that have not started yet or ended before the window.
            if ($planStart->greaterThanOrEqualTo($windowEnd)) {
                continue;
            }

            if ($planEnd !== null && $planEnd->lessThan($windowStart)) {
                continue;
            }

            $daily = [];
            $expected = 0;

            foreach ($dayKeys as $dayKey) {
                $day = Carbon::parse($dayKey)->startOfDay();
                $inPlan = $day->greaterThanOrEqualTo($planStart)
                    && ($planEnd === null || $day->lessThanOrEqualTo($planEnd));

                $dayExpected = $inPlan ? $plan->doses_per_day : 0;
                $expected += $dayExpected;

                $daily[$dayKey] = [
                    'date' => $dayKey,
                    'expected' => $dayExpected,
                    'taken' => 0,
                    'skipped' => 0,
                    'missed' => 0,
                    'in_plan' => $inPlan,
                ];
            }

            foreach ($this->recordsIn($plan, $windowStart, $windowEnd) as $record) {
                $slot = $record->scheduled_for ?? $record->recorded_at;

                if ($slot === null) {
                    continue;
                }

                $dayKey = $slot->copy()->startOfDay()->toDateString();

                if (! isset($daily[$dayKey]) || ! $daily[$dayKey]['in_plan']) {
                    continue;
                }

                $status = $record->status;

                if (! array_key_exists($status, $daily[$dayKey])) {
                    continue;
                }

                $daily[$dayKey][$status]++;
            }

            $taken = 0;
            $skipped = 0;
            $missed = 0;
            $breakdown = [];

            foreach ($daily as $day) {
                $taken += $day['taken'];
                $skipped += $day['skipped'];
                $missed += $day['missed'];

                unset($day['in_plan']);

                $day['percent'] = self::percent($day['taken'], $day['expected']);
                $breakdown[] = $day;
            }

            $rows[] = [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'expected' => $expected,
                'taken' => $taken,
                'skipped' => $skipped,
                'missed' => $missed,
                'percent' => self::percent($taken, $expected),
                'daily' => $breakdown,
            ];
        }

        return [
            'from' => $windowStart->toDateString(),
            'to' => $windowEnd->copy()->subDay()->toDateString(),
            'days' => $days,
            'plans' => $rows,
        ];
    }

    /**
     * @return Collection<int, MedicationRecord>
     */
    private function recordsIn(MedicationPlan $plan, Carbon $windowStart, Carbon $windowEnd)
    {
        return MedicationRecord::query()
            ->where('tenant_id', $plan->tenant_id)
            ->where('workspace_id', $plan->workspace_id)
            ->where('profile_id', $plan->profile_id)
            ->where('plan_id', $plan->id)
            ->where('recorded_at', '>=', $windowStart->copy()->subHours(self::QUERY_MARGIN_HOURS))
            ->where('recorded_at', '<', $windowEnd->copy()->addHours(self::QUERY_MARGIN_HOURS))
            ->get();
    }

    private static function percent(int $taken, int $expected): float
    {
        if ($expected === 0) {
            return 0.0;
        }

        return round($taken / $expected * 100, 1);
    }
}
