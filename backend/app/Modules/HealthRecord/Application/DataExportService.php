<?php

namespace App\Modules\HealthRecord\Application;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\HealthRecord\Domain\DataExportNotFoundException;
use App\Modules\HealthRecord\Domain\DataExportStorageUnavailableException;
use App\Modules\HealthRecord\Domain\ExportExpiredException;
use App\Modules\HealthRecord\Domain\ExportNotPermittedException;
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
    /**
     * Absolute cap for a signed export link (`.ai/03` E7: <= 15 minutes).
     * Configuration may shorten it; it can never lengthen it.
     */
    private const MAX_LINK_TTL_MINUTES = 15;

    public function __construct(
        private readonly SecurityAuditLogger $audit,
        private readonly DataExportSections $sections,
    ) {}

    /**
     * Queue an export, or refuse with the remaining 24-hour window.
     *
     * E8: the request is refused before a row exists when the requester may
     * not read any section, because such an export could only be empty.
     */
    public function request(Workspace $workspace, HealthProfile $profile, User $actor): DataExport
    {
        $this->assertProfileBelongsToWorkspace($workspace, $profile);

        if ($this->sections->decide($actor, $workspace->id, $profile->id)['included'] === []) {
            throw new ExportNotPermittedException;
        }

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
     * The URL lifetime is the shorter of the configured 15-minute cap and the
     * export's own remaining lifetime. The caller has already been authorized
     * for `data_export.download` and `data_export` consent by the route.
     *
     * E7/F-08: the sections the export actually contains are re-authorized twice
     * before any capability is minted - once for the export's requester (whose
     * grants decided the contents) and once for the caller of this endpoint (who
     * must not receive a copy of data they could not read themselves). A grant
     * revoked after the build turns the row `revoked`; a narrower caller is
     * refused without destroying the requester's export.
     *
     * @return array{url: string, expires_at: Carbon}
     */
    public function link(
        Workspace $workspace,
        HealthProfile $profile,
        string $exportId,
        User $caller,
    ): array {
        $export = $this->find($workspace, $profile, $exportId);

        $this->expireIfNeeded($export);

        if ($export->status === DataExport::STATUS_REVOKED) {
            throw new ExportNotPermittedException;
        }

        if ($export->status === DataExport::STATUS_EXPIRED || $export->status === DataExport::STATUS_FAILED) {
            throw new ExportExpiredException;
        }

        if ($export->status !== DataExport::STATUS_READY) {
            throw new ExportNotReadyException;
        }

        $this->assertSectionsStillReadable($export, $profile, $workspace, $caller);

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
            self::MAX_LINK_TTL_MINUTES,
            (int) config('exports.link_ttl_minutes', self::MAX_LINK_TTL_MINUTES),
            (int) config('exports.max_link_ttl_minutes', self::MAX_LINK_TTL_MINUTES),
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

    /**
     * Re-run the build-time section decision against the current grants.
     *
     * The requester is the actor who asked for the export, not necessarily the
     * caller of the link endpoint: an export is that person's record copy, and
     * the route has already authorized whoever is downloading.
     */
    private function assertSectionsStillReadable(
        DataExport $export,
        HealthProfile $profile,
        Workspace $workspace,
        User $caller,
    ): void {
        $sections = is_array($export->included_sections) ? $export->included_sections : [];

        if ($sections === []) {
            // A ready row without a section list cannot be re-authorized. Fail
            // closed rather than guessing that it is safe (F-09).
            $this->revoke($export, 'sections_missing');

            throw new ExportNotPermittedException;
        }

        $requester = User::query()->whereKey($export->requested_by)->first();

        if (! $requester instanceof User) {
            $this->revoke($export, 'requester_unavailable');

            throw new ExportNotPermittedException;
        }

        $access = $this->sections->decide($requester, $workspace->id, $profile->id, $sections);

        if ($access['withheld'] !== []) {
            $this->revoke($export, 'access_revoked');

            throw new ExportNotPermittedException;
        }

        // The caller must also be able to read everything the file contains:
        // `data_export.download` is authority to download, not authority to
        // read a section. A narrower caller is refused WITHOUT revoking, so a
        // third party cannot destroy the requester's export by probing it.
        if ($caller->id !== $requester->id) {
            $callerAccess = $this->sections->decide($caller, $workspace->id, $profile->id, $sections);

            if ($callerAccess['withheld'] !== []) {
                throw new ExportNotPermittedException;
            }
        }
    }

    private function revoke(DataExport $export, string $reason): void
    {
        DB::transaction(function () use ($export, $reason): void {
            $fresh = DataExport::query()
                ->whereKey($export->id)
                ->lockForUpdate()
                ->first();

            // Only one caller may record the transition. A concurrent request
            // that already revoked the row is a no-op, not a second audit row.
            if (! $fresh instanceof DataExport || $fresh->status !== DataExport::STATUS_READY) {
                return;
            }

            $fresh->forceFill([
                'status' => DataExport::STATUS_REVOKED,
                'error_code' => 'access_revoked',
            ])->save();

            $this->audit->record([
                'actor_id' => null,
                'actor_type' => 'system',
                'action' => 'data_export.revoked',
                'target_type' => 'data_export',
                'target_id' => $fresh->id,
                'result' => 'allow',
                'metadata' => [
                    'reason' => $reason,
                    'status' => DataExport::STATUS_REVOKED,
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
