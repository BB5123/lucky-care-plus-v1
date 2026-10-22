<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Domain\QuietHours;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's notification and communication choices.
 *
 * Per user, not per workspace: the row follows the person across memberships.
 * Changing a channel here never grants, widens, or withdraws data access, and
 * is never a consent action (ABD-016) — no code in this module touches
 * `consent_records`.
 */
class CommunicationPreference extends Model
{
    public const LOCALE_TH = 'th';

    public const LOCALE_EN = 'en';

    public const LOCALES = [self::LOCALE_TH, self::LOCALE_EN];

    public const DIGEST_IMMEDIATE = 'immediate';

    public const DIGEST_DAILY = 'daily';

    public const DIGESTS = [self::DIGEST_IMMEDIATE, self::DIGEST_DAILY];

    /** @var list<string> */
    public const CHANNELS = ['in_app', 'push', 'line'];

    protected $table = 'communication_preferences';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'locale',
        'channels',
        'quiet_hours',
        'digest',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'channels' => 'array',
            'quiet_hours' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommunicationPreference $preference) {
            $preference->id ??= Uuid7::generate();
        });
    }

    /**
     * Account defaults: in-app and push on, LINE off, Thai, immediate.
     *
     * @return array{locale: string, channels: array<string, bool>, quiet_hours: null, digest: string}
     */
    public static function defaults(): array
    {
        return [
            'locale' => self::LOCALE_TH,
            'channels' => [
                'in_app' => true,
                'push' => true,
                'line' => false,
            ],
            'quiet_hours' => null,
            'digest' => self::DIGEST_IMMEDIATE,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * A channel is only attempted when the stored flag is exactly `true`.
     * Unknown/missing flags fail closed (no delivery row).
     */
    public function channelEnabled(string $channel): bool
    {
        $channels = is_array($this->channels) ? $this->channels : [];

        return ($channels[$channel] ?? false) === true;
    }

    public function quietHours(): ?QuietHours
    {
        if (! is_array($this->quiet_hours)) {
            return null;
        }

        return QuietHours::fromArray($this->quiet_hours);
    }
}
