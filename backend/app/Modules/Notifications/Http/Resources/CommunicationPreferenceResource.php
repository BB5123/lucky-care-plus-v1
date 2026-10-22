<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\CommunicationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The actor's own preference DTO.
 *
 * @mixin CommunicationPreference
 */
class CommunicationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'locale' => $this->locale,
            'channels' => [
                'in_app' => $this->channelEnabled('in_app'),
                'push' => $this->channelEnabled('push'),
                'line' => $this->channelEnabled('line'),
            ],
            'quiet_hours' => $this->quiet_hours === null ? null : [
                'start' => $this->quiet_hours['start'] ?? null,
                'end' => $this->quiet_hours['end'] ?? null,
            ],
            'digest' => $this->digest,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
