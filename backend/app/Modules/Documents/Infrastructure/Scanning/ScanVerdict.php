<?php

namespace App\Modules\Documents\Infrastructure\Scanning;

/**
 * The result of one malware scan.
 *
 * `verdict` is the wire form the rest of the document pipeline reasons about:
 * `clean`, `infected:<signature>` or `error:<reason>`. It never contains a
 * filename or file bytes, so it is safe to put in an audit row (N-02).
 */
final class ScanVerdict
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const ERROR = 'error';

    private function __construct(
        public readonly string $name,
        public readonly string $verdict,
        public readonly string $rawOutput,
    ) {}

    public static function clean(string $name, string $rawOutput = ''): self
    {
        return new self($name, self::CLEAN, $rawOutput);
    }

    public static function infected(string $name, string $signature, string $rawOutput = ''): self
    {
        return new self($name, self::INFECTED.':'.$signature, $rawOutput);
    }

    public static function error(string $name, string $reason, string $rawOutput = ''): self
    {
        return new self($name, self::ERROR.':'.$reason, $rawOutput);
    }

    public function status(): string
    {
        return explode(':', $this->verdict, 2)[0];
    }

    public function signature(): ?string
    {
        return $this->isInfected() ? explode(':', $this->verdict, 2)[1] : null;
    }

    public function reason(): ?string
    {
        return $this->isError() ? explode(':', $this->verdict, 2)[1] : null;
    }

    public function isClean(): bool
    {
        return $this->status() === self::CLEAN;
    }

    public function isInfected(): bool
    {
        return $this->status() === self::INFECTED;
    }

    public function isError(): bool
    {
        return $this->status() === self::ERROR;
    }
}
