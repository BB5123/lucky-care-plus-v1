<?php

namespace App\Modules\People\Models;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonRelationship extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'from_person_id',
        'to_person_id',
        'kinship',
    ];

    protected static function booted(): void
    {
        static::creating(function (PersonRelationship $relationship) {
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

    public function fromPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'from_person_id');
    }

    public function toPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'to_person_id');
    }
}
