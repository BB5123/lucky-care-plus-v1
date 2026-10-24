<?php

namespace App\Modules\CareCrm\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\CareCrm\Domain\CareTaskTemplateNotFoundException;
use App\Modules\CareCrm\Domain\VersionConflictException;
use App\Modules\CareCrm\Models\CareTaskTemplate;
use App\Modules\CareCrm\Models\CareTaskTemplateException;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRUD use cases for recurring care-task templates (P2.1).
 *
 * The template is its own aggregate. Nothing in this service ever writes a
 * `care_tasks` row: occurrences are owned by {@see CareTaskMaterializer}, so a
 * schedule edit cannot rewrite history (RC-003, CRM-021).
 */
final class CareTaskTemplateService
{
    /** Fields PATCH may change. Occurrences are deliberately not among them. */
    private const UPDATABLE_FIELDS = [
        'title',
        'description',
        'tier',
        'type',
        'priority',
        'frequency',
        'interval',
        'days_of_week',
        'times_of_day',
        'timezone',
        'starts_on',
        'ends_on',
        'evidence_policy',
        'verification_required',
        'assignee_strategy',
        'assigned_user_id',
        'is_active',
    ];

    public function __construct(private readonly BusinessAuditLogger $audit) {}

    /**
     * @return Collection<int, CareTaskTemplate>
     */
    public function listForProfile(HealthProfile $profile): Collection
    {
        return $this->scoped($profile)
            ->withCount('exceptions')
            ->orderByDesc('is_active')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
    }

    public function find(HealthProfile $profile, string $templateId): CareTaskTemplate
    {
        $template = $this->scoped($profile)
            ->with(['exceptions' => fn ($query) => $query->orderBy('occurrence_date')->orderBy('id')])
            ->whereKey($templateId)
            ->first();

        if (! $template instanceof CareTaskTemplate) {
            throw new CareTaskTemplateNotFoundException;
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(HealthProfile $profile, User $actor, array $input): CareTaskTemplate
    {
        $strategy = (string) ($input['assignee_strategy'] ?? CareTaskTemplate::STRATEGY_UNASSIGNED);
        $assignedUserId = $strategy === CareTaskTemplate::STRATEGY_FIXED
            ? (int) ($input['assigned_user_id'] ?? 0)
            : null;

        if ($strategy === CareTaskTemplate::STRATEGY_FIXED && $assignedUserId <= 0) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['A fixed template requires an assigned user.'],
            ]);
        }

        if ($assignedUserId !== null) {
            $this->assertAssigneeIsMember($profile->workspace_id, $assignedUserId);
        }

        $frequency = (string) $input['frequency'];
        $template = new CareTaskTemplate([
            'tenant_id' => $profile->tenant_id,
            'workspace_id' => $profile->workspace_id,
            'profile_id' => $profile->id,
            'created_by' => $actor->id,
            'title' => (string) $input['title'],
            'description' => $input['description'] ?? null,
            'tier' => (string) $input['tier'],
            'type' => (string) $input['type'],
            'priority' => (string) ($input['priority'] ?? 'normal'),
            'frequency' => $frequency,
            'interval' => (int) ($input['interval'] ?? 1),
            'days_of_week' => $frequency === CareTaskTemplate::FREQUENCY_WEEKLY
                ? $this->normalizeDaysOfWeek($input['days_of_week'] ?? [])
                : null,
            'times_of_day' => $this->normalizeTimesOfDay($input['times_of_day'] ?? []),
            'timezone' => (string) ($input['timezone'] ?? 'Asia/Bangkok'),
            'starts_on' => (string) $input['starts_on'],
            'ends_on' => $input['ends_on'] ?? null,
            'evidence_policy' => (string) ($input['evidence_policy'] ?? 'none'),
            'verification_required' => (bool) ($input['verification_required'] ?? false),
            'assignee_strategy' => $strategy,
            'assigned_user_id' => $assignedUserId,
            'is_active' => (bool) ($input['is_active'] ?? true),
            'version' => 1,
        ]);

        $this->assertScheduleIsValid($template);

        return DB::transaction(function () use ($template, $actor): CareTaskTemplate {
            $template->save();

            $this->record($actor, 'care_task.template.create', $template, [
                'frequency' => $template->frequency,
                'interval' => $template->interval,
                'slot_count' => count($template->times_of_day ?? []),
                'assignee_strategy' => $template->assignee_strategy,
                'version' => $template->version,
            ]);

            return $template->fresh(['exceptions']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        HealthProfile $profile,
        string $templateId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): CareTaskTemplate {
        return DB::transaction(function () use ($profile, $templateId, $expectedVersion, $actor, $input): CareTaskTemplate {
            $template = $this->locked($profile, $templateId);

            if ($template->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $changed = [];

            foreach (self::UPDATABLE_FIELDS as $field) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }

                $changed[] = $field;
            }

            if (array_key_exists('title', $input)) {
                $template->title = (string) $input['title'];
            }

            if (array_key_exists('description', $input)) {
                $template->description = $input['description'];
            }

            foreach (['tier', 'type', 'priority', 'frequency', 'timezone', 'evidence_policy', 'assignee_strategy'] as $field) {
                if (array_key_exists($field, $input)) {
                    $template->{$field} = (string) $input[$field];
                }
            }

            if (array_key_exists('interval', $input)) {
                $template->interval = (int) $input['interval'];
            }

            if (array_key_exists('times_of_day', $input)) {
                $template->times_of_day = $this->normalizeTimesOfDay($input['times_of_day'] ?? []);
            }

            if (array_key_exists('starts_on', $input)) {
                $template->starts_on = (string) $input['starts_on'];
            }

            if (array_key_exists('ends_on', $input)) {
                $template->ends_on = $input['ends_on'];
            }

            if (array_key_exists('verification_required', $input)) {
                $template->verification_required = (bool) $input['verification_required'];
            }

            if (array_key_exists('is_active', $input)) {
                $template->is_active = (bool) $input['is_active'];
            }

            // Weekly is the only frequency that owns `days_of_week`; switching
            // away clears it so a later edit cannot silently resurrect stale
            // weekdays.
            if ($template->frequency === CareTaskTemplate::FREQUENCY_WEEKLY) {
                if (array_key_exists('days_of_week', $input)) {
                    $template->days_of_week = $this->normalizeDaysOfWeek($input['days_of_week'] ?? []);
                }
            } else {
                $template->days_of_week = null;
            }

            if ($template->assignee_strategy === CareTaskTemplate::STRATEGY_FIXED) {
                if (array_key_exists('assigned_user_id', $input)) {
                    $template->assigned_user_id = $input['assigned_user_id'] === null
                        ? null
                        : (int) $input['assigned_user_id'];
                }
            } else {
                $template->assigned_user_id = null;
            }

            if ($template->assigned_user_id !== null) {
                $this->assertAssigneeIsMember($profile->workspace_id, $template->assigned_user_id);
            }

            $this->assertScheduleIsValid($template);

            $template->version = $template->version + 1;
            $template->save();

            // Field names only: `description` is encrypted free text (N-02).
            $this->record($actor, 'care_task.template.update', $template, [
                'changed_fields' => $changed,
                'version' => $template->version,
                // Explicitly documents the boundary the acceptance suite and
                // RC-003 require: already-materialised occurrences are untouched.
                'affects' => 'future_occurrences_only',
            ]);

            return $template->fresh(['exceptions']);
        });
    }

    public function deactivate(
        HealthProfile $profile,
        string $templateId,
        int $expectedVersion,
        User $actor,
    ): CareTaskTemplate {
        return DB::transaction(function () use ($profile, $templateId, $expectedVersion, $actor): CareTaskTemplate {
            $template = $this->locked($profile, $templateId);

            if ($template->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            if ($template->is_active) {
                $template->is_active = false;
                $template->version = $template->version + 1;
                $template->save();

                $this->record($actor, 'care_task.template.deactivate', $template, [
                    'is_active' => false,
                    'version' => $template->version,
                    'affects' => 'future_occurrences_only',
                ]);
            }

            return $template->fresh(['exceptions']);
        });
    }

    /**
     * Apply (or replace) a skip/reschedule override for one local date.
     *
     * The template version is the optimistic-locking token: an override changes
     * the schedule future runs will see, so it participates in the same
     * `If-Match` contract as PATCH.
     *
     * @param  array<string, mixed>  $input
     */
    public function addException(
        HealthProfile $profile,
        string $templateId,
        int $expectedVersion,
        User $actor,
        array $input,
    ): CareTaskTemplateException {
        $action = (string) $input['action'];
        $occurrenceDate = Carbon::parse((string) $input['occurrence_date'])->toDateString();

        if (! in_array($action, [
            CareTaskTemplateException::ACTION_SKIP,
            CareTaskTemplateException::ACTION_RESCHEDULE,
        ], true)) {
            throw ValidationException::withMessages([
                'action' => ['The action must be skip or reschedule.'],
            ]);
        }

        $rescheduledTo = null;

        if ($action === CareTaskTemplateException::ACTION_RESCHEDULE) {
            $rawRescheduledTo = trim((string) ($input['rescheduled_to'] ?? ''));

            if ($rawRescheduledTo === '') {
                throw ValidationException::withMessages([
                    'rescheduled_to' => ['A reschedule exception requires the new time.'],
                ]);
            }

            $rescheduledTo = Carbon::parse($rawRescheduledTo)->utc();
        }

        return DB::transaction(function () use (
            $profile,
            $templateId,
            $expectedVersion,
            $actor,
            $input,
            $action,
            $occurrenceDate,
            $rescheduledTo,
        ): CareTaskTemplateException {
            $template = $this->locked($profile, $templateId);

            if ($template->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $reason = $input['reason'] ?? null;

            $exception = CareTaskTemplateException::query()
                ->where('template_id', $template->id)
                ->whereDate('occurrence_date', $occurrenceDate)
                ->first();

            if (! $exception instanceof CareTaskTemplateException) {
                $exception = new CareTaskTemplateException([
                    'tenant_id' => $template->tenant_id,
                    'workspace_id' => $template->workspace_id,
                    'template_id' => $template->id,
                    'created_by' => $actor->id,
                ]);
            }

            $exception->occurrence_date = $occurrenceDate;
            $exception->action = $action;
            $exception->rescheduled_to = $rescheduledTo;
            $exception->reason = $reason;
            $exception->save();

            $template->version = $template->version + 1;
            $template->save();

            $this->record($actor, 'care_task.template.exception.upsert', $template, [
                'exception_action' => $action,
                'occurrence_date' => $occurrenceDate,
                'rescheduled' => $rescheduledTo !== null,
                'reason_present' => is_string($reason) && $reason !== '',
                'version' => $template->version,
            ]);

            return $exception->fresh(['template']);
        });
    }

    /**
     * @return Builder<CareTaskTemplate>
     */
    private function scoped(HealthProfile $profile): Builder
    {
        return CareTaskTemplate::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id);
    }

    private function locked(HealthProfile $profile, string $templateId): CareTaskTemplate
    {
        $template = $this->scoped($profile)
            ->whereKey($templateId)
            ->lockForUpdate()
            ->first();

        if (! $template instanceof CareTaskTemplate) {
            throw new CareTaskTemplateNotFoundException;
        }

        return $template;
    }

    /**
     * @return list<int>
     */
    private function normalizeDaysOfWeek(mixed $days): array
    {
        $normalized = [];

        foreach ((array) $days as $day) {
            $normalized[] = (int) $day;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * Sort and de-duplicate `HH:MM` slots so materialisation order is stable.
     *
     * @return list<string>
     */
    private function normalizeTimesOfDay(mixed $times): array
    {
        $normalized = [];

        foreach ((array) $times as $time) {
            $normalized[] = (string) $time;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function assertScheduleIsValid(CareTaskTemplate $template): void
    {
        $errors = [];

        if (! in_array($template->frequency, [
            CareTaskTemplate::FREQUENCY_DAILY,
            CareTaskTemplate::FREQUENCY_WEEKLY,
            CareTaskTemplate::FREQUENCY_MONTHLY,
        ], true)) {
            $errors['frequency'][] = 'The frequency must be daily, weekly or monthly.';
        }

        if ($template->interval < 1 || $template->interval > 30) {
            $errors['interval'][] = 'The interval must be between 1 and 30.';
        }

        $days = $template->days_of_week ?? [];

        if ($template->frequency === CareTaskTemplate::FREQUENCY_WEEKLY) {
            if ($days === []) {
                $errors['days_of_week'][] = 'A weekly template requires at least one weekday.';
            }

            foreach ($days as $day) {
                if ($day < 1 || $day > 7) {
                    $errors['days_of_week'][] = 'Weekdays use ISO numbers 1 (Monday) to 7 (Sunday).';

                    break;
                }
            }
        }

        $times = $template->times_of_day ?? [];

        if (count($times) < 1 || count($times) > 6) {
            $errors['times_of_day'][] = 'A template needs between one and six times of day.';
        }

        foreach ($times as $time) {
            if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors['times_of_day'][] = 'Times must be local HH:MM values.';

                break;
            }
        }

        if (! in_array($template->timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'][] = 'The timezone must be an IANA identifier.';
        }

        if (
            $template->ends_on !== null
            && Carbon::parse($template->ends_on)->toDateString() < Carbon::parse($template->starts_on)->toDateString()
        ) {
            $errors['ends_on'][] = 'The end date must be on or after the start date.';
        }

        if (
            $template->assignee_strategy === CareTaskTemplate::STRATEGY_FIXED
            && $template->assigned_user_id === null
        ) {
            $errors['assigned_user_id'][] = 'A fixed template requires an assigned user.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertAssigneeIsMember(string $workspaceId, int $userId): void
    {
        $exists = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['The assignee must be an active member of this workspace.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(User $actor, string $action, CareTaskTemplate $template, array $metadata): void
    {
        $this->audit->record([
            'actor_id' => $actor->id,
            'actor_type' => 'user',
            'action' => $action,
            'target_type' => 'care_task_template',
            'target_id' => $template->id,
            'result' => 'allow',
            'metadata' => $metadata,
        ]);
    }
}
