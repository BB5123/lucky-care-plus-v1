<?php

namespace App\Modules\AiGateway\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily usage ledger for the AI quota (`.ai/08-AI-GATEWAY.md` section 4).
 */
class AiUsageCounter extends Model
{
    protected $table = 'ai_usage_counters';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'actor_id',
        'day',
        'requests',
        'tokens',
        'cost_minor',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'day' => 'date',
            'requests' => 'integer',
            'tokens' => 'integer',
            'cost_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiUsageCounter $counter) {
            $counter->id ??= Uuid7::generate();
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
