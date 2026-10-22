<?php

namespace App\Modules\People\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $table = 'persons';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'user_id',
        'display_name',
        'birth_date',
        'managed_by_person_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Person $person) {
            $person->id ??= Uuid7::generate();
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'managed_by_person_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(Person::class, 'managed_by_person_id');
    }

    public function contactPoints(): HasMany
    {
        return $this->hasMany(ContactPoint::class, 'person_id');
    }

    public function relationshipsFrom(): HasMany
    {
        return $this->hasMany(PersonRelationship::class, 'from_person_id');
    }

    public function relationshipsTo(): HasMany
    {
        return $this->hasMany(PersonRelationship::class, 'to_person_id');
    }
}
