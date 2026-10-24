<?php

namespace App\Modules\HealthRecord\Models;

use App\Modules\Consent\Models\AccessGrant;
use App\Modules\Consent\Models\ConsentRecord;
use App\Modules\People\Models\CareRelationship;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Database\Factories\HealthProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The health profile a grant/consent points at. One profile per person.
 */
class HealthProfile extends Model
{
    /** @use HasFactory<HealthProfileFactory> */
    use HasFactory;

    protected $table = 'health_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'person_id',
        'managed_by_person_id',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** Live profiles only: an archived profile is not a health-entry point. */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    protected static function booted(): void
    {
        static::creating(function (HealthProfile $profile) {
            $profile->id ??= Uuid7::generate();
        });
    }

    protected static function newFactory(): HealthProfileFactory
    {
        return HealthProfileFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'managed_by_person_id');
    }

    public function careRelationships(): HasMany
    {
        return $this->hasMany(CareRelationship::class, 'profile_id');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class, 'profile_id');
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class, 'profile_id');
    }
}
