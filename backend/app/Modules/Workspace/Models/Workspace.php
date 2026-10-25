<?php

namespace App\Modules\Workspace\Models;

use App\Modules\People\Models\Person;
use App\Modules\Retention\Domain\RetentionState;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

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
            // Lifecycle state is server-managed only; it is not fillable, so a
            // request body can never move an account between states (N-01).
            'retention_state' => RetentionState::class,
            'retention_state_changed_at' => 'datetime',
            'retention_expires_at' => 'datetime',
            'lapsed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->id ??= Uuid7::generate();
            // Every workspace starts active. The database default says the same
            // thing; setting it here keeps the in-memory model consistent before
            // a reload (and the lifecycle service refuses a null state).
            $workspace->retention_state ??= RetentionState::Active;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * The retention state as an enum, failing closed when the model is stale.
     *
     * The column is NOT NULL with a default, so a null here means the instance
     * was built without the column; guessing `active` would silently open an
     * account that might be read-only or archived (F-09).
     */
    public function retentionState(): RetentionState
    {
        $state = $this->retention_state;

        if ($state instanceof RetentionState) {
            return $state;
        }

        if ($state === null || $state === '') {
            throw new LogicException(
                'Workspace has no retention state loaded; reload the model before reading its lifecycle.'
            );
        }

        return RetentionState::from((string) $state);
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

    /**
     * Workspaces that may receive scheduled background work (P2b).
     *
     * This is the one place the "is this account still processed?" filter is
     * expressed for jobs: reminders, task materialisation, escalation and the
     * inactivity scan all start from this scope instead of re-checking the
     * state themselves. The retention jobs deliberately do NOT use it - purge,
     * compaction and the state advance must process lapsed accounts too.
     *
     * @param  Builder<Workspace>  $query
     * @return Builder<Workspace>
     */
    public function scopeAcceptingBackgroundWork(Builder $query): Builder
    {
        return $query->where('retention_state', RetentionState::Active->value);
    }
}
