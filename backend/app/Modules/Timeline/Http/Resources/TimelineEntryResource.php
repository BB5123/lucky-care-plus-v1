<?php

namespace App\Modules\Timeline\Http\Resources;

use App\Modules\Timeline\Models\TimelineEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One timeline row.
 *
 * A redacted row exposes only `type`, `occurred_at` and `redacted: true`
 * (CRM-014): "something happened" without the source reference, translation
 * key or metadata. That is what lets a timeline reader see that an event
 * exists without gaining a back door into a scope it was not granted (F-06).
 *
 * @mixin TimelineEntry
 */
final class TimelineEntryResource extends JsonResource
{
    private bool $redacted = false;

    public function redacted(bool $redacted = true): self
    {
        $this->redacted = $redacted;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->redacted) {
            return [
                'type' => (string) $this->type,
                'occurred_at' => $this->occurred_at?->toIso8601String(),
                'redacted' => true,
            ];
        }

        return [
            'id' => (string) $this->id,
            'type' => (string) $this->type,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'summary_key' => (string) $this->summary_key,
            'ref' => [
                'type' => (string) $this->ref_type,
                'id' => (string) $this->ref_id,
            ],
            'metadata' => $this->metadata ?? [],
            'redacted' => false,
        ];
    }
}
