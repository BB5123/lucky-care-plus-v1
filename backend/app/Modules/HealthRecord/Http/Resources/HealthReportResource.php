<?php

namespace App\Modules\HealthRecord\Http\Resources;

use App\Modules\HealthRecord\Models\HealthReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Report metadata. The frozen `payload` is only returned by the detail/create
 * endpoints; the list stays cheap and does not stream every snapshot.
 *
 * @mixin HealthReport
 */
class HealthReportResource extends JsonResource
{
    public function __construct(HealthReport $resource, private readonly bool $withPayload = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'profile_id' => $this->profile_id,
            'kind' => $this->kind,
            'generated_by' => $this->generated_by,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'status' => $this->status,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->withPayload) {
            $data['payload'] = $this->payload;
        }

        return $data;
    }
}
