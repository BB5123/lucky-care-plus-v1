<?php

namespace App\Modules\Documents\Models;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Metadata for one private medical document.
 *
 * The object is addressed only by `storage_path`, which is encrypted at rest
 * and never exposed by an API response. `original_name` is encrypted for the
 * same reason (a filename can contain a person's name). `deleted_at` is the
 * soft-delete marker used by the document endpoint; a queued job removes the
 * object, and retention performs the hard delete after its window.
 */
class MedicalDocument extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_DELETED = 'deleted';

    public const SCAN_SKIPPED = 'skipped';

    public const SCAN_CLEAN = 'clean';

    public const SCAN_INFECTED = 'infected';

    protected $table = 'medical_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'profile_id',
        'uploaded_by',
        'kind',
        'mime_type',
        'size_bytes',
        'sha256',
        'storage_disk',
        'storage_path',
        'original_name',
        'captured_at',
        'scan_status',
        'status',
        'version',
    ];

    protected function casts(): array
    {
        return [
            // The object path is a capability-ish locator; encrypting it means
            // a database dump alone does not reveal the private layout.
            'storage_path' => 'encrypted',
            'original_name' => 'encrypted',
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'captured_at' => 'datetime',
            'version' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MedicalDocument $document) {
            $document->id ??= Uuid7::generate();
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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * A document can back evidence only while it is available and scanned (or
     * explicitly exempt from scanning). Pending, quarantined, infected and
     * deleted rows fail closed.
     */
    public function isAttachable(): bool
    {
        return $this->deleted_at === null
            && $this->status === self::STATUS_AVAILABLE
            && in_array($this->scan_status, [self::SCAN_CLEAN, self::SCAN_SKIPPED], true);
    }
}
