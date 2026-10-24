<?php

namespace App\Modules\AiGateway\Models;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata-only record of one Context Gateway attempt (AIG-007 / N-02).
 *
 * The model deliberately exposes no prompt, output or context columns: the
 * only traceability is `record_refs` (identifiers) and `context_hash`.
 */
class AiRequest extends Model
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_DISABLED = 'disabled';

    public const PURPOSE_SUMMARY = 'summary';

    public const PURPOSE_DRAFT = 'draft';

    public const PURPOSE_OCR = 'ocr';

    public const PURPOSE_KNOWLEDGE = 'knowledge';

    protected $table = 'ai_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'actor_id',
        'purpose',
        'provider',
        'model',
        'status',
        'error_code',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'cost_minor',
        'record_refs',
        'context_hash',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_minor' => 'integer',
            'record_refs' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiRequest $request) {
            $request->id ??= Uuid7::generate();
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
