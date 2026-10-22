<?php

namespace App\Modules\Retention\Services;

/**
 * Result of one purge batch. Contains counts only — never row contents (N-02).
 */
final readonly class PurgeReport
{
    public function __construct(
        public string $type,
        public int $evaluated,
        public int $purged,
        public int $held,
        public bool $dryRun,
        public bool $skipped = false,
        public ?string $reason = null,
    ) {}

    public static function skipped(string $type, string $reason, bool $dryRun): self
    {
        return new self(
            type: $type,
            evaluated: 0,
            purged: 0,
            held: 0,
            dryRun: $dryRun,
            skipped: true,
            reason: $reason,
        );
    }

    public function merge(self $other): self
    {
        if ($this->skipped && $other->skipped) {
            return new self(
                type: $this->type,
                evaluated: 0,
                purged: 0,
                held: 0,
                dryRun: $this->dryRun,
                skipped: true,
                reason: $this->reason ?? $other->reason,
            );
        }

        return new self(
            type: $this->type,
            evaluated: $this->evaluated + $other->evaluated,
            purged: $this->purged + $other->purged,
            held: $this->held + $other->held,
            dryRun: $this->dryRun,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'evaluated' => $this->evaluated,
            'purged' => $this->purged,
            'held' => $this->held,
            'dry_run' => $this->dryRun,
            'skipped' => $this->skipped,
            'reason' => $this->reason,
        ];
    }
}
