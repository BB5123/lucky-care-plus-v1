<?php

namespace App\Modules\Documents\Jobs;

use App\Modules\Documents\Models\MedicalDocument;
use App\Support\Database\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Removes a soft-deleted document's object off the request path.
 *
 * The job carries only identifiers (no path, filename, or bytes) so a queue
 * payload can never leak PHI (N-02). It opens its own tenant/workspace context
 * because a worker has no request transaction, and it keeps the metadata row:
 * retention is the only path that hard-deletes the row.
 */
final class DeleteDocumentObject implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $workspaceId,
    ) {}

    public function handle(): void
    {
        TenantContext::run($this->tenantId, $this->workspaceId, function (): void {
            $document = MedicalDocument::withTrashed()
                ->whereKey($this->documentId)
                ->first();

            if (! $document instanceof MedicalDocument) {
                return;
            }

            $path = $document->storage_path;

            if (! is_string($path) || $path === '') {
                return;
            }

            $disk = Storage::disk($document->storage_disk);

            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException(
                    'The document object could not be deleted; the job will retry.'
                );
            }
        });
    }
}
