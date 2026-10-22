<?php

namespace App\Modules\Workspace\Models;

use App\Modules\People\Models\Person;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'settings',
        'break_glass_enabled',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'break_glass_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->id ??= Uuid7::generate();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'workspace_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'workspace_id');
    }

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class, 'workspace_id');
    }
}
