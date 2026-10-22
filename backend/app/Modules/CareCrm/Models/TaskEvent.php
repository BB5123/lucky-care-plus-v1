<?php

namespace App\Modules\CareCrm\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only transition record (CT-004 / CRM-019).
 *
 * The database trigger forbids UPDATE for every role and forbids DELETE for
 * every application path; the model therefore exposes no updated-at column
 * and must only ever be inserted. The retention purge may delete rows after
 * 36 months (`.ai/06 §8.1`) by opting in with `app.retention_purge`; DM-R05
 * protects the audit chain, not this operational history.
 */
class TaskEvent extends Model
{
    protected $table = 'task_events';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'task_id',
        'actor_id',
        'actor_type',
        'from_status',
        'to_status',
        'event',
        'reason',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'actor_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TaskEvent $event) {
            $event->id ??= Uuid7::generate();
            $event->occurred_at ??= now();
            $event->created_at ??= now();
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
