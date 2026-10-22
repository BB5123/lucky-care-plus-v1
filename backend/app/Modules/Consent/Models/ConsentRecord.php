<?php

namespace App\Modules\Consent\Models;

use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Database\Factories\ConsentRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable consent record (CD-001...006, DM-006).
 *
 * There is no `updated_at`: the only allowed mutation is the withdrawal
 * transition, enforced by a database trigger. New consent = new row.
 */
class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use HasFactory;

    protected $table = 'consent_records';

    protected $keyType = 'string';

    public $incrementing = false;

    /** Rows have a single `created_at`; an `updated_at` column does not exist. */
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'consent_type',
        'purpose',
        'scope',
        'policy_version',
        'consenting_subject_id',
        'legal_basis_type',
        'granted_at',
        'withdrawn_at',
        'evidence_reference',
        'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ConsentRecord $consent) {
            $consent->id ??= Uuid7::generate();
            $consent->created_at ??= now();
        });
    }

    protected static function newFactory(): ConsentRecordFactory
    {
        return ConsentRecordFactory::new();
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'consenting_subject_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class, 'superseded_by_id');
    }
}
