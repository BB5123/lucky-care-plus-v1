<?php

namespace App\Modules\HealthRecord\Models;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A permitted medical condition on a health profile.
 *
 * `notes` is free text and therefore field-encrypted (.ai/06 §6); it must
 * never appear in logs, notifications or audit metadata (N-02).
 */
class HealthCondition extends Model
{
    use SoftDeletes;

    protected $table = 'health_conditions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'recorded_by',
        'source',
        'code',
        'name',
        'diagnosed_at',
        'status',
        'notes',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'diagnosed_at' => 'date',
            'notes' => 'encrypted',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (HealthCondition $condition) {
            $condition->id ??= Uuid7::generate();
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
}
