<?php

namespace App\Modules\CareCrm\Models;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of a workspace's escalation ladder (`.ai/05 §6`).
 *
 * A workspace owns at most one row per `level`; the unique index is the
 * backstop for the application-level "levels are contiguous from 1" rule.
 * `notify_roles` contains membership roles from the frozen baseline role
 * table (never permission ids, never user ids).
 */
class EscalationPolicy extends Model
{
    public const ACTION_NOTIFY = 'notify';

    public const ACTION_REASSIGN_TO_ADMIN = 'reassign_to_admin';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_NOTIFY,
        self::ACTION_REASSIGN_TO_ADMIN,
    ];

    /**
     * The membership roles defined by docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md
     * §3. These are roles, not permissions: the escalation surface needs no new
     * permission id.
     *
     * @var list<string>
     */
    public const ROLES = [
        'profile_owner',
        'legal_guardian',
        'workspace_admin',
        'caregiver',
        'viewer',
        'healthcare_professional',
        'platform_support',
        'platform_admin',
    ];

    protected $table = 'escalation_policies';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'level',
        'after_minutes',
        'notify_assignee',
        'notify_roles',
        'action',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'after_minutes' => 'integer',
            'notify_assignee' => 'boolean',
            'notify_roles' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EscalationPolicy $policy) {
            $policy->id ??= Uuid7::generate();
            $policy->notify_roles ??= [];
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
}
