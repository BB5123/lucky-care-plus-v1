<?php

namespace App\Modules\Documents\Application;

use App\Models\User;
use App\Modules\Audit\Services\BusinessAuditLogger;
use App\Modules\Documents\Domain\DocumentNotFoundException;
use App\Modules\Documents\Domain\DocumentScanUnavailableException;
use App\Modules\Documents\Domain\DocumentStorageUnavailableException;
use App\Modules\Documents\Jobs\DeleteDocumentObject;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Upload, read, delete and signed-download use cases for documents.
 *
 * Permission decisions are made by route middleware through the single
 * `Authorizer` entry point. This service owns only the storage and domain
 * rules that cannot be expressed as a permission: MIME sniffing, the opaque
 * key layout, the scan gate, and retention-friendly soft deletion.
 */
final class DocumentService
{
    public function __construct(private readonly BusinessAuditLogger $audit) {}

    public function paginate(
        Workspace $workspace,
        HealthProfile $profile,
        int $limit,
        ?string $cursor,
    ): CursorPaginator {
        $this->assertProfileBelongsToWorkspace($workspace, $profile);

        return MedicalDocument::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('profile_id', $profile->id)
            ->where('status', MedicalDocument::STATUS_AVAILABLE)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    /**
     * @param  array{kind: string, captured_at?: string|null}  $input
     */
    public function upload(
        Workspace $workspace,
        HealthProfile $profile,
        User $actor,
        UploadedFile $file,
        array $input,
    ): MedicalDocument {
        $this->assertProfileBelongsToWorkspace($workspace, $profile);

        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be read.'],
            ]);
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);

        if (! is_string($detectedMime) || $detectedMime === '') {
            $detectedMime = 'application/octet-stream';
        }

        $allowed = (array) config('documents.allowed_mimes', []);

        if (! in_array($detectedMime, $allowed, true)) {
            // Never echo a guessed type back to the client; it can be used to
            // probe the sniffer.
            throw ValidationException::withMessages([
                'file' => ['This file type is not accepted.'],
            ]);
        }

        // A client that claims a known type must not disagree with the bytes.
        // Unknown client types (e.g. application/octet-stream) are ignored and
        // the sniffed type wins.
        $clientMime = $file->getClientMimeType();

        if (is_string($clientMime) && in_array($clientMime, $allowed, true) && $clientMime !== $detectedMime) {
            throw ValidationException::withMessages([
                'file' => ['The declared file type does not match the file contents.'],
            ]);
        }

        $size = (int) $file->getSize();
        $maxBytes = (int) config('documents.max_bytes', 10 * 1024 * 1024);

        if ($size < 1) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file is empty.'],
            ]);
        }

        if ($size > $maxBytes) {
            // Thai copy names the exact configured ceiling (product language).
            throw ValidationException::withMessages([
                'file' => ['ไฟล์ต้องมีขนาดไม่เกิน '.$maxBytes.' ไบต์'],
            ]);
        }

        // Fail closed before touching the object store or the database. This
        // deployment has no scanner, so a required scan can never be satisfied
        // here (F-09).
        if ((bool) config('documents.scan_required', false)) {
            throw new DocumentScanUnavailableException;
        }

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum) || $checksum === '') {
            throw new DocumentStorageUnavailableException;
        }

        $documentId = Uuid7::generate();
        $disk = (string) config('documents.disk', 'local');
        // Exactly `documents/{tenant_uuid}/{document_uuid}`: no profile id, no
        // user filename, no extension, and no health data (N-02).
        $storagePath = 'documents/'.$workspace->tenant_id.'/'.$documentId;

        $stored = Storage::disk($disk)->putFileAs(
            dirname($storagePath),
            $file,
            basename($storagePath),
        );

        if ($stored === false) {
            throw new DocumentStorageUnavailableException;
        }

        try {
            return DB::transaction(function () use (
                $workspace,
                $profile,
                $actor,
                $input,
                $disk,
                $storagePath,
                $detectedMime,
                $size,
                $checksum,
                $documentId,
                $file,
            ): MedicalDocument {
                // The primary key is not mass-assignable, so it must be
                // forced; the storage path above was built from this exact id
                // and the two must never diverge.
                $document = MedicalDocument::query()->forceCreate([
                    'id' => $documentId,
                    'tenant_id' => $workspace->tenant_id,
                    'workspace_id' => $workspace->id,
                    'profile_id' => $profile->id,
                    'uploaded_by' => $actor->id,
                    'kind' => $input['kind'],
                    'mime_type' => $detectedMime,
                    'size_bytes' => $size,
                    'sha256' => $checksum,
                    'storage_disk' => $disk,
                    'storage_path' => $storagePath,
                    'original_name' => $file->getClientOriginalName(),
                    'captured_at' => $input['captured_at'] ?? null,
                    'scan_status' => MedicalDocument::SCAN_SKIPPED,
                    'status' => MedicalDocument::STATUS_AVAILABLE,
                    'version' => 1,
                ]);

                $this->audit->record([
                    'actor_id' => $actor->id,
                    'actor_type' => 'user',
                    'action' => 'document.upload',
                    'target_type' => 'medical_document',
                    'target_id' => $document->id,
                    'result' => 'allow',
                    'metadata' => [
                        'kind' => $document->kind,
                        'mime_type' => $document->mime_type,
                        'size_bytes' => $document->size_bytes,
                    ],
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            // A row that was not committed must not leave an orphan object.
            Storage::disk($disk)->delete($storagePath);

            throw $exception;
        }
    }

    public function findAvailable(
        Workspace $workspace,
        HealthProfile $profile,
        string $documentId,
    ): MedicalDocument {
        $this->assertProfileBelongsToWorkspace($workspace, $profile);

        $document = MedicalDocument::query()
            ->where('tenant_id', $workspace->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('profile_id', $profile->id)
            ->where('status', MedicalDocument::STATUS_AVAILABLE)
            ->whereKey($documentId)
            ->first();

        if (! $document instanceof MedicalDocument) {
            throw new DocumentNotFoundException;
        }

        return $document;
    }

    /**
     * Soft-delete metadata and hand object removal to the queue.
     *
     * The bytes must never be deleted inline with the response: a slow or
     * failed store call would either delay the response or roll back metadata
     * after the object is already gone. The queued job owns that failure.
     */
    public function delete(
        Workspace $workspace,
        HealthProfile $profile,
        string $documentId,
        User $actor,
    ): void {
        $document = $this->findAvailable($workspace, $profile, $documentId);

        DB::transaction(function () use ($document, $actor): void {
            $document->forceFill([
                'status' => MedicalDocument::STATUS_DELETED,
                'version' => $document->version + 1,
            ])->save();
            $document->delete();

            $this->audit->record([
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'action' => 'document.delete',
                'target_type' => 'medical_document',
                'target_id' => $document->id,
                'result' => 'allow',
                'metadata' => [
                    'kind' => $document->kind,
                ],
            ]);
        });

        DeleteDocumentObject::dispatch(
            $document->id,
            $document->tenant_id,
            $document->workspace_id,
        );
    }

    /**
     * Issue a short-lived capability link.
     *
     * The signed delivery route re-runs the Authorizer for the document's
     * profile, so a revoked grant or a leaked link never expands access
     * (ABD-014 / F-08).
     *
     * @return array{url: string, expires_at: Carbon}
     */
    public function temporaryDownloadUrl(
        MedicalDocument $document,
        ?int $expiresInMinutes = null,
    ): array {
        $max = max(1, (int) config('documents.url_max_ttl_minutes', 15));
        $default = (int) config('documents.url_ttl_minutes', 5);
        $minutes = $expiresInMinutes ?? $default;
        $minutes = max(1, min($minutes, $max));

        $expiresAt = now()->addMinutes($minutes);

        $url = URL::temporarySignedRoute(
            'documents.download',
            $expiresAt,
            ['document' => $document->id],
            absolute: false,
        );

        return ['url' => $url, 'expires_at' => $expiresAt];
    }

    private function assertProfileBelongsToWorkspace(Workspace $workspace, HealthProfile $profile): void
    {
        if ($profile->tenant_id !== $workspace->tenant_id || $profile->workspace_id !== $workspace->id) {
            throw new DocumentNotFoundException;
        }
    }
}
