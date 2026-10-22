<?php

namespace App\Modules\CareCrm\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence attached to a care task (CT-005 / CRM-008).
 *
 * `note` is free text and therefore encrypted at rest; it is never included in
 * the need-to-know card projection.
 */
class TaskEvidence extends Model
{
    public const TYPE_VALUE = 'value';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_NOTE = 'note';

    protected $table = 'task_evidence';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'task_id',
        'type',
        'metric',
        'value',
        'unit',
        'document_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'note' => 'encrypted',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TaskEvidence $evidence) {
            $evidence->id ??= Uuid7::generate();
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
