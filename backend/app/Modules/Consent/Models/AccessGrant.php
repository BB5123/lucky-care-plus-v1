<?php

namespace App\Modules\Consent\Models;

use App\Models\User;
use App\Modules\Consent\Services\AuthzVersion;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Database\Factories\AccessGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The actual data permission for a profile (CD-010...016).
 *
 * `resource_scope` and `allowed_actions` are arrays of tokens; consumers must
 * check both (see GrantChecker), never just the presence of a row.
 */
class AccessGrant extends Model
{
    /** @use HasFactory<AccessGrantFactory> */
    use HasFactory;

    protected $table = 'access_grants';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'granted_by',
        'grantee_user_id',
        'resource_scope',
        'allowed_actions',
        'purpose',
        'valid_from',
        'expires_at',
        'status',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'constraints',
    ];

    protected function casts(): array
    {
        return [
            'granted_by' => 'integer',
            'grantee_user_id' => 'integer',
            'revoked_by' => 'integer',
            'resource_scope' => 'array',
            'allowed_actions' => 'array',
            'constraints' => 'array',
            'valid_from' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AccessGrant $grant) {
            $grant->id ??= Uuid7::generate();
        });

        // F-08 / `.ai/03-AUTHORIZATION.md §7`: a grant mutation (create,
        // update, revoke, delete) must make every cached dashboard/decision
        // key for this workspace unreachable. Model events cover every write
        // path, including future services, rather than relying on each caller.
        static::saved(fn (AccessGrant $grant) => AuthzVersion::bump((string) $grant->workspace_id));
        static::deleted(fn (AccessGrant $grant) => AuthzVersion::bump((string) $grant->workspace_id));
    }

    protected static function newFactory(): AccessGrantFactory
    {
        return AccessGrantFactory::new();
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

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function grantee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_user_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
