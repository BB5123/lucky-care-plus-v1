<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt for one (recipient, channel) pair.
 *
 * The ledger is append-only in spirit: a row is created `queued`, may move
 * once to `sent` or `failed`, and `read_at` may be set once (a second read
 * request is an idempotent no-op). Nothing here stores message bodies; the
 * `params` payload is limited to translation placeholders by the dispatcher
 * before insertion (N-02).
 */
class NotificationDelivery extends Model
{
    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_LINE = 'line';

    public const CHANNEL_EMAIL = 'email';

    /** @var list<string> */
    public const CHANNELS = [
        self::CHANNEL_IN_APP,
        self::CHANNEL_PUSH,
        self::CHANNEL_LINE,
        self::CHANNEL_EMAIL,
    ];

    /**
     * Channels a normal notification may use.
     *
     * Email is deliberately excluded: it is reserved for the legal retention
     * notices, which override the user's channel preferences because PDPA
     * requires telling someone before deleting their account (R7).
     *
     * @var list<string>
     */
    public const DEFAULT_CHANNELS = [self::CHANNEL_IN_APP, self::CHANNEL_PUSH, self::CHANNEL_LINE];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    public const ERROR_NOT_CONFIGURED = 'not_configured';

    protected $table = 'notification_deliveries';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'workspace_id',
        'recipient_user_id',
        'channel',
        'template_key',
        'params',
        'status',
        'attempts',
        'scheduled_for',
        'sent_at',
        'read_at',
        'error_code',
        'subject_type',
        'subject_id',
    ];

    protected function casts(): array
    {
        return [
            'recipient_user_id' => 'integer',
            'params' => 'array',
            'attempts' => 'integer',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NotificationDelivery $delivery) {
            $delivery->id ??= Uuid7::generate();
        });
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }
}
