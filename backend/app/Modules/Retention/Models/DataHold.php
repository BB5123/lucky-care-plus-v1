<?php

namespace App\Modules\Retention\Models;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A legal hold that blocks purging for its workspace (and optionally one
 * profile). `until = null` means indefinite.
 */
class DataHold extends Model
{
    protected $table = 'data_holds';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'reason',
        'created_by',
        'until',
        'released_at',
        'released_by',
    ];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'released_by' => 'integer',
            'until' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DataHold $hold) {
            $hold->id ??= Uuid7::generate();
        });
    }

    /**
     * Unreleased and not yet expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('released_at')
            ->where(function (Builder $query): void {
                $query->whereNull('until')->orWhere('until', '>', now());
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
