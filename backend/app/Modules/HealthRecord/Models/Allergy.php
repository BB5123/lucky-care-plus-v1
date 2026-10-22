<?php

namespace App\Modules\HealthRecord\Models;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A known allergy on a health profile.
 *
 * Severity is a closed vocabulary (`mild|moderate|severe`) enforced in the
 * domain layer and by a database check constraint.
 */
class Allergy extends Model
{
    use SoftDeletes;

    protected $table = 'allergies';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'recorded_by',
        'source',
        'substance',
        'reaction',
        'severity',
        'noted_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'noted_at' => 'date',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Allergy $allergy) {
            $allergy->id ??= Uuid7::generate();
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
