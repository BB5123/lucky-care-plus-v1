<?php

namespace App\Modules\Retention\Services;

use App\Modules\Retention\Exceptions\RetentionOverrideException;
use App\Modules\Workspace\Models\Tenant;
use InvalidArgumentException;

/**
 * Resolves the effective retention period for a data type (ADR-016 §1/§4).
 *
 * Resolution order:
 *   1. a type listed in `retention.non_overridable` always uses the platform
 *      default (`audit_months` must never be user-configurable);
 *   2. a tenant override, which must be an integer inside
 *      `retention.bounds[type]` (out of range => DM-R06 rejection);
 *   3. the platform default.
 */
final class RetentionPolicy
{
    /**
     * @return int|null months, or null when the type is kept indefinitely
     */
    public function monthsFor(string $type, ?Tenant $tenant): ?int
    {
        $default = $this->defaultFor($type);

        if ($this->isNonOverridable($type)) {
            if ($tenant !== null
                && is_array($tenant->retention_override)
                && array_key_exists($type, $tenant->retention_override)) {
                throw RetentionOverrideException::notOverridable($type);
            }

            return $default;
        }

        $override = $this->overrideFor($type, $tenant);

        if ($override === null) {
            return $default;
        }

        return $override;
    }

    /**
     * Validate every override on a tenant, not only the type being read.
     *
     * Called when settings are written, so an invalid payload is rejected
     * before it can influence a purge run (DM-R06).
     */
    public function assertValidOverrides(Tenant $tenant): void
    {
        foreach (array_keys($tenant->retention_override ?? []) as $type) {
            $this->monthsFor($type, $tenant);
        }
    }

    /**
     * Validate one override payload without a Tenant instance.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function assertValidOverridePayload(array $overrides): void
    {
        $tenant = new Tenant(['retention_override' => $overrides]);

        foreach (array_keys($overrides) as $type) {
            $this->monthsFor($type, $tenant);
        }
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function boundsFor(string $type): ?array
    {
        $bounds = config("retention.bounds.{$type}");

        if (! is_array($bounds) || count($bounds) !== 2) {
            return null;
        }

        return [(int) $bounds[0], (int) $bounds[1]];
    }

    public function isNonOverridable(string $type): bool
    {
        return in_array($type, (array) config('retention.non_overridable', []), true);
    }

    private function defaultFor(string $type): ?int
    {
        $defaults = (array) config('retention.defaults', []);

        if (! array_key_exists($type, $defaults)) {
            throw new InvalidArgumentException("Unknown retention type [{$type}].");
        }

        $value = $defaults[$type];

        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Retention default for [{$type}] must be an integer or null.");
        }

        return (int) $value;
    }

    private function overrideFor(string $type, ?Tenant $tenant): ?int
    {
        if ($tenant === null) {
            return null;
        }

        $overrides = $tenant->retention_override;

        if (! is_array($overrides) || ! array_key_exists($type, $overrides)) {
            return null;
        }

        if ($this->isNonOverridable($type)) {
            throw RetentionOverrideException::notOverridable($type);
        }

        $bounds = $this->boundsFor($type);

        if ($bounds === null) {
            // A type without bounds is not meant to be tenant-configurable.
            throw RetentionOverrideException::notOverridable($type);
        }

        $value = $overrides[$type];

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw RetentionOverrideException::invalidValue($type);
        }

        $months = (int) $value;
        [$min, $max] = $bounds;

        if ($months < $min || $months > $max) {
            throw RetentionOverrideException::outOfBounds($type, $min, $max);
        }

        return $months;
    }
}
