<?php

namespace App\Modules\CareCrm\Models;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recurring care-task schedule (`.ai/05 §4`).
 *
 * The template is intentionally separate from the occurrences it materialises:
 * a PATCH changes only future runs, never a row in `care_tasks` (RC-003).
 * Free-text `description` is encrypted at rest and must never enter audit
 * metadata, logs, or notification payloads (N-02).
 */
class CareTaskTemplate extends Model
{
    use SoftDeletes;

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const STRATEGY_FIXED = 'fixed';

    public const STRATEGY_ROTATING = 'rotating';

    public const STRATEGY_UNASSIGNED = 'unassigned';

    protected $table = 'care_task_templates';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'created_by',
        'title',
        'description',
        'tier',
        'type',
        'priority',
        'frequency',
        'interval',
        'days_of_week',
        'times_of_day',
        'timezone',
        'starts_on',
        'ends_on',
        'evidence_policy',
        'verification_required',
        'assignee_strategy',
        'assigned_user_id',
        'is_active',
        'version',
    ];

    protected function casts(): array
    {
        return [
            // Free-text health detail: encrypted at rest (.ai/06 §6).
            'description' => 'encrypted',
            'days_of_week' => 'array',
            'times_of_day' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'interval' => 'integer',
            'verification_required' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
            'created_by' => 'integer',
            'assigned_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CareTaskTemplate $template) {
            $template->id ??= Uuid7::generate();
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(CareTaskTemplateException::class, 'template_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CareTask::class, 'template_id');
    }
}
