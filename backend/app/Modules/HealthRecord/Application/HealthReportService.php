<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\HealthRecord\Domain\HealthReportNotFoundException;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\HealthRecord\Models\HealthReport;
use App\Modules\HealthRecord\Models\ReportShare;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for the Doctor Brief snapshot (`.ai/05 §9`).
 *
 * Creating a report always inserts a new row: the payload is frozen so a doctor
 * holding a link to an older brief keeps seeing what was signed. Revoking a
 * report is one-way and also closes every outstanding share immediately.
 */
final class HealthReportService
{
    /** Server-side ceiling from DB-001. */
    private const MAX_PERIOD_DAYS = 180;

    /** Default window: the last 30 days, inclusive of today. */
    private const DEFAULT_PERIOD_DAYS = 30;

    public function __construct(
        private readonly DoctorBriefBuilder $builder,
        private readonly BusinessAuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, HealthReport>
     */
    public function list(HealthProfile $profile): Collection
    {
        return HealthReport::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function find(HealthProfile $profile, string $reportId): HealthReport
    {
        $report = HealthReport::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('workspace_id', $profile->workspace_id)
            ->where('profile_id', $profile->id)
            ->whereKey($reportId)
            ->first();

        if (! $report instanceof HealthReport) {
            throw new HealthReportNotFoundException;
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(
        Workspace $workspace,
        HealthProfile $profile,
        User $actor,
        array $input,
    ): HealthReport {
        [$from, $to] = $this->period($input);

        // Section-level checks (and their audit rows) happen before the insert:
        // a caregiver's partial grant must shape the snapshot itself, not be
        // applied when someone later reads it.
        $payload = $this->builder->build($workspace, $profile, $actor, $from, $to);

        return DB::transaction(function () use ($profile, $actor, $from, $to, $payload): HealthReport {
            $report = HealthReport::query()->create([
                'tenant_id' => $profile->tenant_id,
                'workspace_id' => $profile->workspace_id,
                'profile_id' => $profile->id,
                'kind' => HealthReport::KIND_DOCTOR_BRIEF,
                'generated_by' => $actor->id,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'payload' => $payload,
                'status' => HealthReport::STATUS_ACTIVE,
                'version' => 1,
            ]);

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'health_report.create',
                'target_type' => 'health_report',
                'target_id' => $report->id,
                'result' => 'allow',
                'metadata' => [
                    'kind' => $report->kind,
                    'period_from' => $report->period_from->toDateString(),
                    'period_to' => $report->period_to->toDateString(),
                    'sections' => $report->payload['sections'] ?? [],
                    'version' => $report->version,
                ],
            ]);

            return $report;
        });
    }

    /**
     * Revoke a report and every share of it. Idempotent: a second call is a
     * no-op and leaves the already-revoked rows untouched.
     */
    public function revokeReport(Workspace $workspace, string $reportId, User $actor): HealthReport
    {
        return DB::transaction(function () use ($workspace, $reportId, $actor): HealthReport {
            $report = HealthReport::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($reportId)
                ->lockForUpdate()
                ->first();

            if (! $report instanceof HealthReport) {
                throw new HealthReportNotFoundException;
            }

            $changed = $report->status !== HealthReport::STATUS_REVOKED;

            if ($changed) {
                $report->forceFill([
                    'status' => HealthReport::STATUS_REVOKED,
                    'version' => $report->version + 1,
                ])->save();
            }

            // Close every outstanding capability in the same transaction so a
            // revoke can never race a still-valid link (F-08, DB-003).
            ReportShare::query()
                ->where('tenant_id', $report->tenant_id)
                ->where('workspace_id', $report->workspace_id)
                ->where('report_id', $report->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($changed) {
                $this->audit->record([
                    'actor_id' => $actor->id,
                    'actor_type' => 'user',
                    'action' => 'health_report.revoke',
                    'target_type' => 'health_report',
                    'target_id' => $report->id,
                    'result' => 'allow',
                    'metadata' => ['version' => $report->version],
                ]);
            }

            return $report->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $input): array
    {
        $to = $this->parseDate($input['period_to'] ?? null) ?? CarbonImmutable::today();
        $from = $this->parseDate($input['period_from'] ?? null)
            ?? $to->subDays(self::DEFAULT_PERIOD_DAYS);

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'period_from' => ['The report period start must be on or before its end.'],
            ]);
        }

        if ((int) $from->diffInDays($to) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'period_to' => ['A Doctor Brief may cover at most 180 days.'],
            ]);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: null;
    }
}
