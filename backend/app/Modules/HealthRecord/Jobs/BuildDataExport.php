<?php

namespace App\Modules\HealthRecord\Jobs;

use App\Models\User;
use App\Modules\Audit\Services\SecurityAuditLogger;
use App\Modules\Authz\Authorizer;
use App\Modules\Authz\ResourceRef;
use App\Modules\HealthRecord\Application\DataExportBuilder;
use App\Modules\HealthRecord\Domain\DataExportBuildFailure;
use App\Modules\HealthRecord\Models\DataExport;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Support\Database\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * Builds one profile's data export off the request path.
 *
 * A worker has no request transaction, so the job opens its own tenant and
 * workspace context for every phase. It carries identifiers only (export,
 * tenant, workspace), never a profile name, a health value or an object path.
 *
 * State machine:
 *   queued -> building -> ready
 *                      -> failed
 *
 * `building` is committed in its own transaction before the slow work starts,
 * so a failed build is visible. If the process dies between phases, the row
 * remains `building` and a retry may recover it after the stale window; a
 * hard crash is not misreported as success.
 *
 * The job re-runs the single Authorizer entry point for `data_export.request`
 * before reading any health data. A grant or consent revoked after the HTTP
 * request therefore takes effect on the queued path too (F-08), and the row
 * ends `failed` with `error_code = access_revoked` instead of producing a file.
 */
final class BuildDataExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A `building` row older than this may be rebuilt by a retry. */
    private const STALE_BUILDING_MINUTES = 15;

    private ?SecurityAuditLogger $audit = null;

    public function __construct(
        public readonly string $exportId,
        public readonly string $tenantId,
        public readonly string $workspaceId,
    ) {}

    public function handle(
        DataExportBuilder $builder,
        Authorizer $authorizer,
        SecurityAuditLogger $audit,
    ): void {
        $this->audit = $audit;

        $diskName = (string) config('exports.disk', 'local');

        // A public bucket is never an acceptable destination for a record copy
        // (`.ai/09` §4). Fail the export instead of writing readable bytes.
        if ($diskName === 'public' || config('filesystems.disks.'.$diskName.'.visibility') === 'public') {
            $this->markFailed(new DataExportBuildFailure('storage_misconfigured'));

            return;
        }

        $disk = Storage::disk($diskName);
        // Deterministic and opaque: no profile id, person name or health word.
        $objectKey = 'exports/'.$this->tenantId.'/'.$this->exportId.'.json';

        try {
            $claimed = TenantContext::run($this->tenantId, $this->workspaceId, function () use ($disk, $objectKey): bool {
                $export = $this->lockExport();

                if (! $export instanceof DataExport) {
                    return false;
                }

                if (in_array($export->status, [
                    DataExport::STATUS_READY,
                    DataExport::STATUS_FAILED,
                    DataExport::STATUS_EXPIRED,
                ], true)) {
                    return false;
                }

                // Another worker is already building the same export. Wait for
                // it; only a stale claim may be recovered.
                if (
                    $export->status === DataExport::STATUS_BUILDING
                    && $export->updated_at !== null
                    && $export->updated_at->gt(now()->subMinutes(self::STALE_BUILDING_MINUTES))
                ) {
                    return false;
                }

                // A previous attempt may have written the object and crashed
                // before recording `ready`. Never let this attempt inherit it.
                if ($disk->exists($objectKey)) {
                    $disk->delete($objectKey);
                }

                if ($export->status !== DataExport::STATUS_BUILDING) {
                    $from = $export->status;
                    $export->forceFill(['status' => DataExport::STATUS_BUILDING])->save();

                    $this->audit($export, 'data_export.building', [
                        'from_status' => $from,
                        'to_status' => DataExport::STATUS_BUILDING,
                    ]);
                }

                return true;
            });

            if (! $claimed) {
                return;
            }

            $export = TenantContext::run(
                $this->tenantId,
                $this->workspaceId,
                fn (): ?DataExport => $this->lockExport(),
            );

            if (! $export instanceof DataExport || $export->status !== DataExport::STATUS_BUILDING) {
                return;
            }

            $actor = User::query()->whereKey($export->requested_by)->first();

            if (! $actor instanceof User) {
                throw new DataExportBuildFailure('requester_unavailable');
            }

            // A separate context/transaction so a denial is audited even though
            // the job then throws and marks the export failed.
            $decision = TenantContext::run(
                $this->tenantId,
                $this->workspaceId,
                fn () => $authorizer->authorize(
                    $actor,
                    'data_export.request',
                    ResourceRef::forProfile($this->workspaceId, $export->profile_id),
                ),
            );

            if (! $decision->allowed()) {
                throw new DataExportBuildFailure('access_revoked');
            }

            $payload = TenantContext::run($this->tenantId, $this->workspaceId, function () use (
                $builder,
                $export,
                $actor,
            ): array {
                $profile = HealthProfile::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('workspace_id', $this->workspaceId)
                    ->whereKey($export->profile_id)
                    ->first();

                if (! $profile instanceof HealthProfile) {
                    throw new DataExportBuildFailure('profile_unavailable');
                }

                return $builder->build($export, $profile, $actor);
            });

            $json = $this->encode($payload);

            // Storage is outside the database transaction. If the write or the
            // subsequent commit fails, the catch cleans the object up.
            if ($disk->put($objectKey, $json) === false) {
                throw new DataExportBuildFailure('storage_unavailable');
            }

            $freshStatus = TenantContext::run($this->tenantId, $this->workspaceId, function () use (
                $objectKey,
                $json,
                $payload,
            ): ?string {
                $export = $this->lockExport();

                if (! $export instanceof DataExport) {
                    return null;
                }

                if ($export->status !== DataExport::STATUS_BUILDING) {
                    return $export->status;
                }

                $byteSize = strlen($json);

                $export->forceFill([
                    'status' => DataExport::STATUS_READY,
                    'object_key' => $objectKey,
                    'byte_size' => $byteSize,
                    'included_sections' => $payload['meta']['included_sections'],
                    'completed_at' => now(),
                    'expires_at' => now()->addDays((int) config('exports.retention_days', 7)),
                    'error_code' => null,
                ])->save();

                $this->audit($export, 'data_export.ready', [
                    'format' => $export->format,
                    'byte_size' => $byteSize,
                    'status' => DataExport::STATUS_READY,
                ]);

                return DataExport::STATUS_READY;
            });

            // Another worker may have won the race and already published the
            // same deterministic object. Only clean up when nobody is ready.
            if ($freshStatus !== DataExport::STATUS_READY) {
                $this->deleteObject($disk, $objectKey);
            }
        } catch (Throwable $exception) {
            $this->deleteObject($disk, $objectKey);
            $this->markFailed($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw new DataExportBuildFailure('encode_failed');
        }
    }

    private function lockExport(): ?DataExport
    {
        return DataExport::query()
            ->where('tenant_id', $this->tenantId)
            ->where('workspace_id', $this->workspaceId)
            ->whereKey($this->exportId)
            ->lockForUpdate()
            ->first();
    }

    private function markFailed(Throwable $exception): void
    {
        $errorCode = $exception instanceof DataExportBuildFailure
            ? $exception->errorCode()
            : 'build_failed';

        try {
            TenantContext::run($this->tenantId, $this->workspaceId, function () use ($errorCode): void {
                $export = $this->lockExport();

                if (! $export instanceof DataExport) {
                    return;
                }

                // Never overwrite a successful build (or an already terminal
                // state) with a late failure from a duplicate worker.
                if (in_array($export->status, [
                    DataExport::STATUS_READY,
                    DataExport::STATUS_FAILED,
                    DataExport::STATUS_EXPIRED,
                ], true)) {
                    return;
                }

                $export->forceFill([
                    'status' => DataExport::STATUS_FAILED,
                    'error_code' => $errorCode,
                    'object_key' => null,
                    'byte_size' => null,
                    'expires_at' => null,
                ])->save();

                $this->audit($export, 'data_export.failed', [
                    'error_code' => $errorCode,
                    'status' => DataExport::STATUS_FAILED,
                ]);
            });
        } catch (Throwable) {
            // A broken database must not turn a failed export into a crash log
            // that carries the exception message. Record identifiers only.
            Log::error('Data export failure state could not be recorded.', [
                'export_id' => $this->exportId,
                'error_code' => $errorCode,
            ]);
        }
    }

    private function deleteObject(Filesystem $disk, string $objectKey): void
    {
        try {
            if ($disk->exists($objectKey)) {
                $disk->delete($objectKey);
            }
        } catch (Throwable) {
            Log::warning('A partial data-export object could not be removed.', [
                'export_id' => $this->exportId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(DataExport $export, string $action, array $metadata): void
    {
        $this->audit?->record([
            'actor_id' => null,
            'actor_type' => 'system',
            'action' => $action,
            'target_type' => 'data_export',
            'target_id' => $export->id,
            'result' => 'allow',
            'metadata' => $metadata,
        ]);
    }
}
