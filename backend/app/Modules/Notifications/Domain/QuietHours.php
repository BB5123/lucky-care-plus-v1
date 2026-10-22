<?php

namespace App\Modules\Notifications\Domain;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The user's quiet window, interpreted in a single timezone.
 *
 * A window may cross midnight (`22:00` → `07:00`). The window is half-open:
 * `[start, end)`. `start === end` means "no quiet hours" rather than a 24-hour
 * block, which is the least surprising reading of an explicitly cleared range.
 *
 * Only `push` and `line` are suppressed; in-app rows are always delivered
 * immediately because they are not an interruption.
 */
final readonly class QuietHours
{
    private function __construct(
        public string $start,
        public string $end,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): ?self
    {
        $start = $value['start'] ?? null;
        $end = $value['end'] ?? null;

        if (! is_string($start) || ! is_string($end)) {
            return null;
        }

        if (! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $start)) {
            return null;
        }

        if (! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $end)) {
            return null;
        }

        return new self($start, $end);
    }

    /**
     * The moment this window ends for a delivery intended at `$now`, or null
     * when `$now` is outside the window. The result is returned in the given
     * timezone; callers persist it as a `timestamptz`.
     */
    public function endFor(CarbonInterface $now, string $timezone): ?CarbonImmutable
    {
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);
        $start = $local->setTimeFromTimeString($this->start);
        $end = $local->setTimeFromTimeString($this->end);

        if ($start->equalTo($end)) {
            return null;
        }

        if ($start->lessThan($end)) {
            // Same-day window, e.g. 12:00 → 14:00.
            return $local->greaterThanOrEqualTo($start) && $local->lessThan($end)
                ? $end
                : null;
        }

        // Cross-midnight window, e.g. 22:00 → 07:00.
        if ($local->greaterThanOrEqualTo($start)) {
            return $end->addDay();
        }

        return $local->lessThan($end) ? $end : null;
    }
}
