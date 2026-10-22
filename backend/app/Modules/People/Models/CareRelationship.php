<?php

namespace App\Modules\People\Models;

use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Social care context (ring / kinship). Deliberately carries no permissions:
 * health data access is always modelled by an access_grant (F-04/F-05).
 */
class CareRelationship extends Model
{
    protected $table = 'care_relationships';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'person_id',
        'ring',
        'relationship_type',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (CareRelationship $relationship) {
            $relationship->id ??= Uuid7::generate();
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

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
