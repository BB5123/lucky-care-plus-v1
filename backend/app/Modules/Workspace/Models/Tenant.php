<?php

namespace App\Modules\Workspace\Models;

use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'retention_override',
    ];

    protected function casts(): array
    {
        return [
            'retention_override' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->id ??= Uuid7::generate();
        });
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'tenant_id');
    }
}
