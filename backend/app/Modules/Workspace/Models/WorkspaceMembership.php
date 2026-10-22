<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMembership extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'user_id',
        'role',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkspaceMembership $membership) {
            $membership->id ??= Uuid7::generate();
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
}
