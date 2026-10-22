<?php

namespace App\Modules\CareCrm\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of responsibility history for a care task.
 *
 * A released row is never deleted: CT-007 requires the previous assignee to
 * remain visible in the history, and the partial unique index only considers
 * rows whose `released_at` is null.
 */
class TaskAssignment extends Model
{
    public const ROLE_PRIMARY = 'primary';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_VERIFIER = 'verifier';

    protected $table = 'task_assignments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'task_id',
        'user_id',
        'role',
        'can_complete',
        'assigned_by',
        'accepted_at',
        'declined_at',
        'decline_reason',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'can_complete' => 'boolean',
            'user_id' => 'integer',
            'assigned_by' => 'integer',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TaskAssignment $assignment) {
            $assignment->id ??= Uuid7::generate();
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(CareTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
