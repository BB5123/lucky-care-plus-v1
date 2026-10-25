<?php

namespace App\Modules\Retention\Models;

use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archive inventory, written when a workspace enters `archived` (P4a).
 *
 * `counts` is per-table row counts; `sha256` is the hash of the canonical JSON
 * of those counts plus the workspace id and archive timestamp, so a manifest
 * that is edited afterwards no longer verifies. It holds counts only - no
 * health values, no names, no free text (N-02).
 */
class WorkspaceArchiveManifest extends Model
{
    protected $table = 'workspace_archive_manifests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'archived_at',
        'counts',
        'sha256',
        'archive_path',
        'archive_sha256',
        'archive_bytes',
        'key_wrapped',
        'key_algorithm',
    ];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkspaceArchiveManifest $manifest) {
            $manifest->id ??= Uuid7::generate();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
