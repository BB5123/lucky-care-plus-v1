<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\HealthRecord\Domain\DataExportNotFoundException;
use App\Modules\HealthRecord\Domain\DataExportStorageUnavailableException;
use App\Modules\HealthRecord\Domain\ExportExpiredException;
use App\Modules\HealthRecord\Domain\ExportNotReadyException;
use App\Modules\HealthRecord\Domain\ExportRateLimitedException;
use App\Modules\HealthRecord\Jobs\BuildDataExport;
use App\Modules\HealthRecord\Models\DataExport;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Request, inspect and link one profile's data export.
 *
 * Authorization is never re-implemented here: the route middleware has already
 * run the single Authorizer pipeline (permission, role, grant, `data_export`
 * consent) before any method below is called. This service owns the privacy
 * window, the state transitions that happen on the request path, and the
 * short-lived signed link.
 */
final class DataExportService
{
    public function __construct(private readonly SecurityAuditLogger $audit) {}

    /**
     * Queue an export, or refuse with the remaining 24-hour window.
     */
    public function request(Workspace $workspace, HealthProfile $profile, User $actor): DataExport
    {
        $this->assertProfileBelongsToWorkspace($workspace, $profile);

        $hours = max(1, (int) config('exports.rate_limit_hours', 24));

        $export = DB::transaction(function () use ($workspace, $profile, $actor, $hours): DataExport {
            // Serialise concurrent requests for one profile so two callers
            // cannot both pass the window check and create two exports.
            HealthProfile::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->first();

            $recent = DataExport::query()
                ->where('profile_id', $profile->id)
                ->where('requested_at', '>', now()->subHours($hours))
                ->orderByDesc('requested_at')
                ->first();

            if ($recent instanceof DataExport && $recent->requested_at instanceof Carbon) {
                $windowEnds = $recent->requested_at->copy()->addHours($hours);
                $remaining = $windowEnds->getTimestamp() - now()->getTimestamp();

                throw new ExportRateLimitedException(max(1, $remaining));
            }

            $export = DataExport::query()->create([
                'tenant_id' => $workspace->tenant_id,
                'workspace_id' => $workspace->id,
                'profile_id' => $profile->id,
                'requested_by' => $actor->id,
                'status' => DataExport::STATUS_QUEUED,
                'format' => DataExport::FORMAT_JSON,
                'object_key' => null,
                'byte_size' => null,
                'expires_at' => null,
                'error_code' => null,
                'requested_at' => now(),
                'completed_at' => null,
            ]);

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'data_export.requested',
                'target_type' => 'data_export',
                'target_id' => $export->id,
                'result' => 'allow',
                'metadata' => [
                    'format' => $export->format,
                    'status' => DataExport::STATUS_QUEUED,
                ],
            ]);

            return $export;
        });

        // A database-queue worker must not see the job before the row commits.
        BuildDataExport::dispatch($export->id, $workspace->tenant_id, $workspace->id)->afterCommit();

        return $export;
    }

    /**
     * Status for the caller. Never exposes the object key or a URL.
     */
    public function show(Workspace $workspace, HealthProfile $profile, string $exportId): DataExport
    {
        $export = $this->find($workspace, $profile, $exportId);

        $this->expireIfNeeded($export);

        return $export;
    }

    /**
     * Mint a signed object-store URL for a ready export.
     *
     * The URL lifetime is the shorter of the configured 30-minute cap and the
     * export's own remaining lifetime. The caller has already been authorized
     * for `data_export.download` and `data_export` consent by the route.
     *
     * @return array{url: string, expires_at: Carbon}
     */
    public function link(Workspace $workspace, HealthProfile $profile, string $exportId): array
    {
        $export = $this->find($workspace, $profile, $exportId);

        $this->expireIfNeeded($export);

        if ($export->status === DataExport::STATUS_EXPIRED || $export->status === DataExport::STATUS_FAILED) {
            throw new ExportExpiredException;
        }

        if ($export->status !== DataExport::STATUS_READY) {
            throw new ExportNotReadyException;
        }

        $objectKey = $export->object_key;
        $diskName = (string) config('exports.disk', 'local');

        // Never mint a capability for a public bucket: the export would be
        // readable without the app's authorization path.
        if ($diskName === 'public' || config('filesystems.disks.'.$diskName.'.visibility') === 'public') {
            throw new DataExportStorageUnavailableException;
        }

        $disk = Storage::disk($diskName);

        if (
            ! is_string($objectKey)
            || $objectKey === ''
            || $export->expires_at === null
            || ! $disk->exists($objectKey)
        ) {
            // A ready row without an object is a lost capability, not a
            // polling state. Mark it expired once and fail closed.
            $this->expire($export, $export->expires_at === null ? 'expiry_missing' : 'object_missing');

            throw new ExportExpiredException;
        }

        $minutes = max(1, min(
            (int) config('exports.link_ttl_minutes', 30),
            (int) config('exports.max_link_ttl_minutes', 30),
        ));

        $expiresAt = now()->addMinutes($minutes);

        if ($export->expires_at->lessThan($expiresAt)) {
            $expiresAt = $export->expires_at->copy();
        }

        try {
            $url = $disk->temporaryUrl($objectKey, $expiresAt);
        } catch (Throwable) {
            throw new DataExportStorageUnavailableException;
        }

        return ['url' => $url, 'expires_at' => $expiresAt];
    }

    private function find(Workspace $workspace, HealthProfile $profile, string $exportId): DataExport
    {
        $this->assertProfileBelongsToWorkspace($workspace, $profile);

        // A malformed id cannot match a row; reject before querying so path
        // input never becomes a query parameter.
        if (! Str::isUuid($exportId)) {
            throw new DataExportNotFoundException;
        }

        $export = DataExport::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('profile_id', $profile->id)
            ->whereKey($exportId)
            ->first();

        if (! $export instanceof DataExport) {
            throw new DataExportNotFoundException;
        }

        return $export;
    }

    private function expireIfNeeded(DataExport $export): void
    {
        if ($export->status !== DataExport::STATUS_READY) {
            return;
        }

        if ($export->expires_at === null || $export->expires_at->isFuture()) {
            return;
        }

        $this->expire($export, 'ttl_elapsed');
    }

    private function expire(DataExport $export, string $reason): void
    {
        DB::transaction(function () use ($export, $reason): void {
            $fresh = DataExport::query()
                ->whereKey($export->id)
                ->lockForUpdate()
                ->first();

            // Only one caller may record the transition. A concurrent request
            // that already expired the row is a no-op, not a second audit row.
            if (! $fresh instanceof DataExport || $fresh->status !== DataExport::STATUS_READY) {
                return;
            }

            $fresh->forceFill(['status' => DataExport::STATUS_EXPIRED])->save();

            $this->audit->record([
                'actor_id' => null,
                'actor_type' => 'system',
                'action' => 'data_export.expired',
                'target_type' => 'data_export',
                'target_id' => $fresh->id,
                'result' => 'allow',
                'metadata' => [
                    'reason' => $reason,
                    'status' => DataExport::STATUS_EXPIRED,
                ],
            ]);
        });

        $export->refresh();
    }

    private function assertProfileBelongsToWorkspace(Workspace $workspace, HealthProfile $profile): void
    {
        if ($profile->tenant_id !== $workspace->tenant_id || $profile->workspace_id !== $workspace->id) {
            throw new DataExportNotFoundException;
        }
    }
}
