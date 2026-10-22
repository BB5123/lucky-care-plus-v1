<?php

namespace App\Modules\CareCrm\Models;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Appointment aggregate root (`appointments`) — a coordination artefact, not a
 * medical record (APT-001).
 *
 * Free-text `purpose`/`location` are encrypted at rest and must never be
 * copied into audit metadata, logs, or notification payloads (N-02).
 */
class Appointment extends Model
{
    use SoftDeletes;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_MISSED = 'missed';

    public const STATUS_NEEDS_FOLLOWUP = 'needs_followup';

    protected $table = 'appointments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'created_by',
        'source',
        'title',
        'provider_name',
        'facility',
        'purpose',
        'location',
        'scheduled_at',
        'duration_minutes',
        'status',
        'cancelled_reason',
        'rescheduled_from_id',
        'transported_by_task_id',
        'prepared_documents',
        'follow_up_task_id',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => 'encrypted',
            'location' => 'encrypted',
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'prepared_documents' => 'array',
            'version' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->id ??= Uuid7::generate();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HealthProfile::class, 'profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transportedByTask(): BelongsTo
    {
        return $this->belongsTo(CareTask::class, 'transported_by_task_id');
    }

    public function followUpTask(): BelongsTo
    {
        return $this->belongsTo(CareTask::class, 'follow_up_task_id');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    /**
     * Statuses that are no longer part of the active plan. `attended` still
     * leads to `needs_followup`, so it is not terminal in the state machine,
     * but it is not "upcoming" either.
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, [
            self::STATUS_RESCHEDULED,
            self::STATUS_CANCELLED,
            self::STATUS_MISSED,
            self::STATUS_NEEDS_FOLLOWUP,
        ], true);
    }
}
