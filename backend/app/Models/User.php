<?php

namespace App\Models;

use App\Modules\Workspace\Models\WorkspaceMembership;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'phone',
    'password',
    'last_login_at',
    'is_platform_admin',
    'platform_admin_granted_at',
    'platform_admin_granted_by',
])]
#[Hidden([
    'password',
    'remember_token',
    'pin_hash',
    'pin_failed_attempts',
    'pin_locked_until',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'pin_failed_attempts' => 'integer',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'platform_admin_granted_at' => 'datetime',
        ];
    }

    /**
     * Platform capabilities (CR-005 / ABD-019) come from this explicit flag,
     * never from a workspace membership role.
     */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'user_id');
    }
}
