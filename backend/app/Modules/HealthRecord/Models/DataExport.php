<?php

namespace App\Modules\HealthRecord\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PDPA data-export job for a health profile.
 *
 * The model holds no health values: only the lifecycle, the opaque object
 * locator and metadata. `object_key` is cast to `encrypted` so a database dump
 * does not reveal where the exported health data lives (the same rule as
 * `medical_documents.storage_path`).
 *
 * Lifecycle: `queued` -> `building` -> `ready`, or `building` -> `failed`.
 * A `ready` export whose `expires_at` has passed becomes `expired`; the link
 * endpoint re-checks the clock before issuing any signed URL.
 */
class DataExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const FORMAT_JSON = 'json';

    protected $table = 'data_exports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'requested_by',
        'status',
        'format',
        'object_key',
        'byte_size',
        'expires_at',
        'error_code',
        'requested_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'object_key' => 'encrypted',
            'byte_size' => 'integer',
            'requested_by' => 'integer',
            'expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DataExport $export): void {
            $export->id ??= Uuid7::generate();
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
