<?php

namespace App\Modules\HealthRecord\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One reported dose event for a plan.
 *
 * A `taken` row is **self-reported, not clinical proof** of ingestion
 * (`.ai/05-CARE-COORDINATION.md` §3, `.ai/02-DOMAIN-MODEL.md` §5 C8). `note`
 * is free text and therefore field-encrypted (`.ai/06 §6`) and never audited.
 */
class MedicationRecord extends Model
{
    use SoftDeletes;

    public const STATUS_TAKEN = 'taken';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_MISSED = 'missed';

    protected $table = 'medication_records';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'plan_id',
        'recorded_by',
        'source',
        'status',
        'scheduled_for',
        'recorded_at',
        'note',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'recorded_at' => 'datetime',
            'note' => 'encrypted',
            'recorded_by' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MedicationRecord $record) {
            $record->id ??= Uuid7::generate();
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

    public function plan(): BelongsTo
    {
        // A record never changes plan, so the relation is a plain lookup.
        return $this->belongsTo(MedicationPlan::class, 'plan_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
