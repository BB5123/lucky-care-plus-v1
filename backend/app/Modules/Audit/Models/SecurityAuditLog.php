<?php

namespace App\Modules\Audit\Models;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Security audit row. Written exclusively by SecurityAuditLogger so the hash
 * chain stays intact; append-only is enforced by a database trigger.
 */
class SecurityAuditLog extends Model
{
    protected $table = 'security_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    /** Rows are written with raw SQL by the logger; there is no `updated_at` flow. */
    public $timestamps = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'occurred_at',
        'actor_id',
        'actor_type',
        'action',
        'target_type',
        'target_id',
        'result',
        'reason_code',
        'workspace_id',
        'metadata',
        'prev_hash',
        'hash',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'actor_id' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
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
