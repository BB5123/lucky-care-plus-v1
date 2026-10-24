<?php

namespace App\Modules\CareCrm\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "this occurrence differs" override for a recurring template.
 *
 * `skip` suppresses the whole local date; `reschedule` creates the occurrence
 * at `rescheduled_to` instead. The row is scoped by the same tenant/workspace
 * pair as the template and protected by forced RLS.
 */
class CareTaskTemplateException extends Model
{
    public const ACTION_SKIP = 'skip';

    public const ACTION_RESCHEDULE = 'reschedule';

    protected $table = 'care_task_template_exceptions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'template_id',
        'occurrence_date',
        'action',
        'rescheduled_to',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
            'rescheduled_to' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CareTaskTemplateException $exception) {
            $exception->id ??= Uuid7::generate();
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(CareTaskTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
