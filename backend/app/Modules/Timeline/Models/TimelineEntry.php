<?php

namespace App\Modules\Timeline\Models;

use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Timeline\Casts\TimelineMetadata;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A projected timeline row (`.ai/02-DOMAIN-MODEL.md §5`, C11).
 *
 * Derived data only: there is no soft delete and no `updated_at`. An entry is
 * upserted by `(ref_type, ref_id, type)` when its source changes, and removed
 * by `timeline:rebuild` when the source row no longer exists.
 */
class TimelineEntry extends Model
{
    protected $table = 'timeline_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'occurred_at',
        'type',
        'ref_type',
        'ref_id',
        'summary_key',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => TimelineMetadata::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TimelineEntry $entry) {
            $entry->id ??= Uuid7::generate();
            $entry->created_at ??= now();
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
}
