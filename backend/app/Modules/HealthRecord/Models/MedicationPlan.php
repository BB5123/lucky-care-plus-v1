<?php

namespace App\Modules\HealthRecord\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A medication plan for one profile.
 *
 * Soft deletes keep the plan reviewable before physical removal (DM-R01).
 * `instructions` is free text and stays field-encrypted at rest (`.ai/06 §6`);
 * it is never written to a log or audit payload (N-02).
 */
class MedicationPlan extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCONTINUED = 'discontinued';

    protected $table = 'medication_plans';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'recorded_by',
        'source',
        'name',
        'dosage',
        'form',
        'route',
        'doses_per_day',
        'schedule_times',
        'starts_on',
        'ends_on',
        'prescribed_by',
        'instructions',
        'status',
        'discontinued_reason',
        'discontinued_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'doses_per_day' => 'integer',
            'schedule_times' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'instructions' => 'encrypted',
            'discontinued_at' => 'datetime',
            'recorded_by' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MedicationPlan $plan) {
            $plan->id ??= Uuid7::generate();
        });
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDiscontinued(): bool
    {
        return $this->status === self::STATUS_DISCONTINUED;
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(MedicationRecord::class, 'plan_id');
    }
}
