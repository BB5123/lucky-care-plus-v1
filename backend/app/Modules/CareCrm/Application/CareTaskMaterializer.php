<?php

namespace App\Modules\CareCrm\Application;

use App\Models\User;
use App\Modules\CareCrm\Models\CareTask;
use App\Modules\CareCrm\Models\CareTaskTemplate;
use App\Modules\CareCrm\Models\CareTaskTemplateException;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceMembership;
use App\Support\Database\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Materialises recurring care-task templates into real `care_tasks` rows.
 *
 * Rules implemented from `.ai/05 §4`:
 *  - the window is `max(starts_on, today) .. min(ends_on, today + daysAhead)`,
 *    walked in the template timezone;
 *  - every row goes through {@see CareTaskService}, so it carries a real
 *    `created` task event and business audit row;
 *  - `occurrence_key` is `Y-m-d\TH:i` in the template timezone and is backed by
 *    the unique `(template_id, occurrence_key)` index, making a second run a
 *    no-op even if two runs overlap;
 *  - `skip` exceptions create nothing, `reschedule` exceptions create at their
 *    new instant;
 *  - a slot that is already in the past is never created.
 *
 * Fairness rule for `rotating`: caregivers are the workspace's active members
 * with role `caregiver`, ordered by `workspace_memberships.id`. The cursor is
 * the number of occurrences already materialised for the template, so the
 * first occurrence goes to the lowest membership id and each subsequent one
 * advances by one. Because the cursor only moves for newly-created rows,
 * re-running the materialiser never reshuffles existing assignments.
 *
 * `unassigned` creates an `open` occurrence. A `routine` occurrence stays
 * `scheduled` regardless of strategy because the state machine has no `open`
 * state for that tier (`.ai/05 §2.2`).
 */
final class CareTaskMaterializer
{
    public function __construct(private readonly CareTaskService $tasks) {}

    /**
     * @return array{created: int, skipped: int, templates: int}
     */
    public function materialise(
        int $daysAhead = 14,
        ?Workspace $only = null,
        bool $dryRun = false,
    ): array {
        $daysAhead = max(0, $daysAhead);
        $totals = ['created' => 0, 'skipped' => 0, 'templates' => 0];

        if ($only instanceof Workspace) {
            return $this->withinWorkspace(
                fn (): array => $this->materialiseWorkspace($only, $daysAhead, $dryRun),
                $only,
            );
        }

        // A scheduler has no request context, so scan the directory and enter
        // every workspace through the trusted tenant/workspace pair (fail
        // closed without it, because every recurring table is RLS-forced).
        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;

            $workspaceTotals = DB::transaction(function () use ($tenantId, $daysAhead, $dryRun): array {
                $result = ['created' => 0, 'skipped' => 0, 'templates' => 0];

                TenantContext::run($tenantId, '', function () use ($tenantId, $daysAhead, $dryRun, &$result): void {
                    // Lapsed/archived accounts are not processed: generating
                    // new work for them would contradict the state model (P2b).
                    $workspaces = Workspace::query()
                        ->where('tenant_id', $tenantId)
                        ->acceptingBackgroundWork()
                        ->orderBy('id')
                        ->get();

                    foreach ($workspaces as $workspace) {
                        $partial = $this->withinWorkspace(
                            fn (): array => $this->materialiseWorkspace($workspace, $daysAhead, $dryRun),
                            $workspace,
                        );

                        $result['created'] += $partial['created'];
                        $result['skipped'] += $partial['skipped'];
                        $result['templates'] += $partial['templates'];
                    }
                });

                return $result;
            });

            $totals['created'] += $workspaceTotals['created'];
            $totals['skipped'] += $workspaceTotals['skipped'];
            $totals['templates'] += $workspaceTotals['templates'];
        }

        return $totals;
    }

    /**
     * @return array{created: int, skipped: int, templates: int}
     */
    private function withinWorkspace(callable $callback, Workspace $workspace): array
    {
        return DB::transaction(
            fn (): array => TenantContext::run(
                (string) $workspace->tenant_id,
                (string) $workspace->id,
                $callback,
            ),
        );
    }

    /**
     * @return array{created: int, skipped: int, templates: int}
     */
    private function materialiseWorkspace(Workspace $workspace, int $daysAhead, bool $dryRun): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'templates' => 0];

        // Fail closed even when a caller targets one workspace directly (the
        // `$only` path): a lapsed or archived account must not receive new
        // generated work (P2b).
        if (! $workspace->retentionState()->acceptsBackgroundWork()) {
            return $result;
        }

        $templates = CareTaskTemplate::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            $result['templates']++;

            $partial = $this->materialiseTemplate($workspace, $template, $daysAhead, $dryRun);
            $result['created'] += $partial['created'];
            $result['skipped'] += $partial['skipped'];
        }

        return $result;
    }

    /**
     * @return array{created: int, skipped: int}
     */
    private function materialiseTemplate(
        Workspace $workspace,
        CareTaskTemplate $template,
        int $daysAhead,
        bool $dryRun,
    ): array {
        $actor = User::query()->find($template->created_by);

        if (! $actor instanceof User) {
            // The FK is restrictive, so this is a defensive guard rather than
            // a normal path: without an actor there is no audit provenance.
            return ['created' => 0, 'skipped' => 0];
        }

        $timezone = (string) $template->timezone;
        $now = CarbonImmutable::now($timezone)->setTimezone($timezone);
        $today = $now->startOfDay();

        $anchor = $this->localDate($template->starts_on->format('Y-m-d'), $timezone);
        $windowStart = $anchor->greaterThan($today) ? $anchor : $today;
        $windowEnd = $today->addDays($daysAhead);

        if ($template->ends_on !== null) {
            $end = $this->localDate($template->ends_on->format('Y-m-d'), $timezone);

            if ($end->lessThan($windowEnd)) {
                $windowEnd = $end;
            }
        }

        if ($windowStart->greaterThan($windowEnd)) {
            return ['created' => 0, 'skipped' => 0];
        }

        // Include soft-deleted occurrences: cancelling an occurrence must not
        // make the next run resurrect it under the same key.
        $existingKeys = CareTask::withTrashed()
            ->where('template_id', $template->id)
            ->whereNotNull('occurrence_key')
            ->pluck('occurrence_key', 'occurrence_key')
            ->all();

        // Rotating cursor: the count already materialised, not the count in
        // this window, so a run never restarts the round-robin.
        $cursor = CareTask::withTrashed()
            ->where('template_id', $template->id)
            ->count();

        $times = $this->orderedTimes($template->times_of_day ?? []);
        $caregiverIds = $this->rotatingCaregiverIds($workspace, $template);

        $exceptions = CareTaskTemplateException::query()
            ->where('template_id', $template->id)
            ->whereBetween('occurrence_date', [
                $windowStart->format('Y-m-d'),
                $windowEnd->format('Y-m-d'),
            ])
            ->get()
            ->keyBy(fn (CareTaskTemplateException $exception): string => $exception->occurrence_date->format('Y-m-d'));

        $created = 0;
        $skipped = 0;

        for ($date = $windowStart; $date->lessThanOrEqualTo($windowEnd); $date = $date->addDay()) {
            if (! $this->matchesSchedule($template, $date, $anchor)) {
                continue;
            }

            $exception = $exceptions->get($date->format('Y-m-d'));
            $slots = [];

            if ($exception instanceof CareTaskTemplateException
                && $exception->action === CareTaskTemplateException::ACTION_SKIP) {
                // Every slot of the day is suppressed; count the day once.
                $skipped++;

                continue;
            }

            if ($exception instanceof CareTaskTemplateException
                && $exception->action === CareTaskTemplateException::ACTION_RESCHEDULE
                && $exception->rescheduled_to !== null) {
                $rescheduled = CarbonImmutable::instance($exception->rescheduled_to)->setTimezone($timezone);

                if (! $rescheduled->greaterThan($now)) {
                    $skipped++;

                    continue;
                }

                $slots[] = $rescheduled;
            } else {
                foreach ($times as $time) {
                    [$hour, $minute] = array_map('intval', explode(':', $time));
                    $slot = $date->setTime($hour, $minute);

                    if (! $slot->greaterThan($now)) {
                        $skipped++;

                        continue;
                    }

                    $slots[] = $slot;
                }
            }

            foreach ($slots as $slot) {
                $key = $slot->format('Y-m-d\TH:i');

                if (array_key_exists($key, $existingKeys)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $created++;
                    $existingKeys[$key] = $key;
                    $cursor++;

                    continue;
                }

                [$assigneeId, $initialStatus] = $this->assignment(
                    $template,
                    $caregiverIds,
                    $cursor,
                );

                [$task, $wasCreated] = $this->tasks->create(
                    $workspace,
                    $actor,
                    [
                        'profile_id' => $template->profile_id,
                        'tier' => $template->tier,
                        'type' => $template->type,
                        'title' => $template->title,
                        'description' => $template->description,
                        'priority' => $template->priority,
                        'scheduled_for' => $slot->utc(),
                        'due_at' => $slot->utc(),
                        'timezone' => $timezone,
                        'evidence_policy' => $template->evidence_policy,
                        'verification_required' => (bool) $template->verification_required,
                        'assignee_user_id' => $assigneeId,
                        'template_id' => $template->id,
                        'occurrence_key' => $key,
                        'source' => 'template',
                    ],
                    "template:{$template->id}:{$key}",
                    false,
                    $initialStatus,
                );

                if ($wasCreated) {
                    $created++;
                    $existingKeys[$key] = $key;

                    if ($template->assignee_strategy === CareTaskTemplate::STRATEGY_ROTATING) {
                        $cursor++;
                    }
                } else {
                    // A concurrent run won the unique-key race.
                    $skipped++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function matchesSchedule(
        CareTaskTemplate $template,
        CarbonImmutable $date,
        CarbonImmutable $anchor,
    ): bool {
        $interval = max(1, (int) $template->interval);

        return match ($template->frequency) {
            CareTaskTemplate::FREQUENCY_DAILY => ((int) $anchor->diffInDays($date)) % $interval === 0,
            CareTaskTemplate::FREQUENCY_WEEKLY => $this->matchesWeekly($template, $date, $anchor, $interval),
            CareTaskTemplate::FREQUENCY_MONTHLY => $this->matchesMonthly($date, $anchor, $interval),
            default => false,
        };
    }

    private function matchesWeekly(
        CareTaskTemplate $template,
        CarbonImmutable $date,
        CarbonImmutable $anchor,
        int $interval,
    ): bool {
        $days = $template->days_of_week ?? [];

        if ($days === [] || ! in_array($date->isoWeekday(), array_map('intval', $days), true)) {
            return false;
        }

        $anchorWeek = $anchor->startOfWeek(CarbonInterface::MONDAY);
        $dateWeek = $date->startOfWeek(CarbonInterface::MONDAY);
        $weeks = intdiv((int) $anchorWeek->diffInDays($dateWeek), 7);

        return $weeks % $interval === 0;
    }

    private function matchesMonthly(CarbonImmutable $date, CarbonImmutable $anchor, int $interval): bool
    {
        // A monthly schedule keeps the start date's day-of-month. Months that
        // do not have that day (e.g. the 31st) are skipped, never clamped.
        if ($date->day !== $anchor->day) {
            return false;
        }

        $months = (($date->year - $anchor->year) * 12) + ($date->month - $anchor->month);

        return $months >= 0 && $months % $interval === 0;
    }

    /**
     * @param  list<string>  $times
     * @return list<string>
     */
    private function orderedTimes(array $times): array
    {
        $times = array_values(array_unique(array_map('strval', $times)));
        sort($times, SORT_STRING);

        return $times;
    }

    /**
     * @return list<int>
     */
    private function rotatingCaregiverIds(Workspace $workspace, CareTaskTemplate $template): array
    {
        if ($template->assignee_strategy !== CareTaskTemplate::STRATEGY_ROTATING) {
            return [];
        }

        return WorkspaceMembership::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->where('role', 'caregiver')
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $caregiverIds
     * @return array{0: ?int, 1: ?string} [assignee user id, forced initial status]
     */
    private function assignment(
        CareTaskTemplate $template,
        array $caregiverIds,
        int $cursor,
    ): array {
        // `.ai/05 §2.5` / the state machine forbid `open` for a routine task;
        // the literal "unassigned -> open" rule therefore applies to the
        // delegated/clinical tiers. A routine occurrence stays `scheduled`
        // whatever the strategy, otherwise it could never be completed.
        $routine = $template->tier === CareTask::TIER_ROUTINE;

        return match ($template->assignee_strategy) {
            CareTaskTemplate::STRATEGY_FIXED => [
                $template->assigned_user_id,
                $routine ? null : CareTask::STATUS_ASSIGNED,
            ],
            CareTaskTemplate::STRATEGY_ROTATING => $caregiverIds === []
                ? [null, $routine ? null : CareTask::STATUS_OPEN]
                : [
                    $caregiverIds[$cursor % count($caregiverIds)],
                    $routine ? null : CareTask::STATUS_ASSIGNED,
                ],
            default => [null, $routine ? null : CareTask::STATUS_OPEN],
        };
    }

    private function localDate(string $date, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
    }
}
