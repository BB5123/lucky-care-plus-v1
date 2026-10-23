<?php

namespace App\Modules\HealthRecord\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-limited capability link to one {@see HealthReport} (DB-003).
 *
 * Only the SHA-256 digest of the token is stored. The raw token exists in the
 * creation response and nowhere else, so a database read cannot reconstruct a
 * working link.
 */
class ReportShare extends Model
{
    protected $table = 'report_shares';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'report_id',
        'token_hash',
        'created_by',
        'expires_at',
        'revoked_at',
        'access_count',
        'last_accessed_at',
        'last_accessed_ip',
        'recipient_label',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'access_count' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReportShare $share) {
            $share->id ??= Uuid7::generate();
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

    public function report(): BelongsTo
    {
        return $this->belongsTo(HealthReport::class, 'report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
