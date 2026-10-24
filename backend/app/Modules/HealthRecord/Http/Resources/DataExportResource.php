<?php

namespace App\Modules\HealthRecord\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a data-export row.
 *
 * `object_key` and any signed URL are deliberately absent. The only way to
 * obtain a URL is the `data_export.download` link endpoint, which re-checks
 * permission, consent and the expiry clock.
 */
final class DataExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'status' => $this->status,
            'format' => $this->format,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'byte_size' => $this->byte_size,
            'error_code' => $this->error_code,
        ];
    }
}
