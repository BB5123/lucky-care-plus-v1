<?php

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Models\MedicalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Client-safe document metadata.
 *
 * `storage_path`, `storage_disk`, and the encrypted ciphertext are deliberately
 * absent: a client addresses a document by its id through the authorized
 * download endpoint, never by a storage path (ABD-014).
 *
 * `original_name` can contain a person's name, so it is only emitted on
 * read-authorized surfaces (list/show), never on the upload acknowledgement.
 *
 * @mixin MedicalDocument
 */
class MedicalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'kind' => $this->kind,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'original_name' => $this->when(
                (bool) $request->attributes->get('documents.expose_original_name', false),
                $this->original_name,
            ),
            'captured_at' => $this->captured_at?->toIso8601String(),
            'scan_status' => $this->scan_status,
            'status' => $this->status,
            'version' => $this->version,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
