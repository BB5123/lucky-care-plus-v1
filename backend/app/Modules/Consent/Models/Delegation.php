<?php

namespace App\Modules\Consent\Models;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legal authority of a representative (L1/L2/L3, ADR-015).
 *
 * A verified delegation does not grant health data access by itself; an
 * access_grant is still required (F-12).
 */
class Delegation extends Model
{
    protected $table = 'delegations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'represented_person_id',
        'representative_user_id',
        'level',
        'evidence_document_id',
        'verified_by',
        'verified_at',
        'expires_at',
        'status',
        'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'representative_user_id' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Delegation $delegation) {
            $delegation->id ??= Uuid7::generate();
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

    public function representedPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'represented_person_id');
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'representative_user_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
