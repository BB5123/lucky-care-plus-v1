<?php

namespace App\Modules\CareCrm\Models;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The care-task aggregate root (`.ai/CRM_DOMAIN_ARCHITECTURE.md` §3).
 *
 * Three independent columns hold workflow state (ADR-001):
 * `status`, `outcome`, and `verification`. `overdue` is computed from
 * `due_at` and is deliberately not a column.
 */
class CareTask extends Model
{
    use SoftDeletes;

    public const TIER_ROUTINE = 'routine';

    public const TIER_DELEGATED = 'delegated';

    public const TIER_CLINICAL = 'clinical';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_OPEN = 'open';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const OUTCOME_DONE = 'done';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_MISSED = 'missed';

    public const OUTCOME_FAILED = 'failed';

    public const VERIFICATION_NOT_REQUIRED = 'not_required';

    public const VERIFICATION_PENDING = 'pending';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_REJECTED = 'rejected';

    public const EVIDENCE_NONE = 'none';

    public const EVIDENCE_VALUE = 'value';

    public const EVIDENCE_PHOTO = 'photo';

    public const EVIDENCE_BOTH = 'both';

    protected $table = 'care_tasks';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'created_by',
        'template_id',
        'occurrence_key',
        'tier',
        'type',
        'title',
        'description',
        'priority',
        'scheduled_for',
        'due_at',
        'timezone',
        'status',
        'outcome',
        'verification',
        'verification_required',
        'evidence_policy',
        'escalation_level',
        'blocked_reason',
        'review_at',
        'completed_at',
        'idempotency_key',
        'version',
        'source',
    ];

    protected function casts(): array
    {
        return [
            // Free-text health detail: encrypted at rest (.ai/06 §6) and never
            // copied into logs or audit metadata (N-02).
            'description' => 'encrypted',
            'scheduled_for' => 'datetime',
            'due_at' => 'datetime',
            'review_at' => 'datetime',
            'completed_at' => 'datetime',
            'verification_required' => 'boolean',
            'escalation_level' => 'integer',
            'version' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CareTask $task) {
            $task->id ??= Uuid7::generate();
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(CareTaskTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'task_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class, 'task_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(TaskEvidence::class, 'task_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('released_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    /**
     * Add the derived `has_photo_evidence` flag as one EXISTS subquery.
     *
     * A photo row only counts when it still points at a document, so the flag
     * mirrors {@see TaskEvidence::TYPE_PHOTO}. It is task metadata, not
     * evidence content: it carries no document id, filename or note, and it
     * costs one subquery for the whole page instead of one query per task.
     */
    public function scopeWithPhotoEvidenceFlag(Builder $query): Builder
    {
        return $query->withExists([
            'evidence as has_photo_evidence' => fn (Builder $evidence) => $evidence
                ->where('type', TaskEvidence::TYPE_PHOTO)
                ->whereNotNull('document_id'),
        ]);
    }

    /**
     * Load the derived flag on an already-hydrated task (create/transition
     * responses), using the same single-query subquery.
     */
    public function loadPhotoEvidenceFlag(): static
    {
        return $this->loadExists([
            'evidence as has_photo_evidence' => fn (Builder $evidence) => $evidence
                ->where('type', TaskEvidence::TYPE_PHOTO)
                ->whereNotNull('document_id'),
        ]);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }
}
