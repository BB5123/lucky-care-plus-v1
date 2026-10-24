<?php

namespace App\Modules\HealthRecord\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A frozen clinical summary (`.ai/05 §9`, DB-001..DB-004).
 *
 * `payload` is an immutable snapshot. A regeneration always inserts a new row:
 * a doctor may be holding a link to the previous version, so mutating it would
 * silently rewrite history.
 */
class HealthReport extends Model
{
    public const KIND_DOCTOR_BRIEF = 'doctor_brief';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'health_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'kind',
        'generated_by',
        'period_from',
        'period_to',
        'payload',
        'status',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'payload' => 'array',
            'status' => 'string',
            'version' => 'integer',
            'generated_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (HealthReport $report) {
            $report->id ??= Uuid7::generate();
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

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ReportShare::class, 'report_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
