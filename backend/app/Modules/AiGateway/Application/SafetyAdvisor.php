<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\ValidatedOutput;

/**
 * Safety advisor for step 5 (`.ai/08-AI-GATEWAY.md` section 6, safety suite).
 *
 * When the context contains a critical vital, the answer must tell the user
 * to contact a clinician or emergency services. The provider is never trusted
 * to remember this: the advisor inspects the scrubbed context with the same
 * cut-offs as the dashboard (`config/dashboard.php`) and appends the standard
 * sentence when the answer omitted it (AIG-006).
 *
 * The advice sentence contains no digits, so it cannot itself fail the
 * grounding check.
 */
final class SafetyAdvisor
{
    /**
     * Thresholds live in `config/dashboard.php`; this class only maps a vital
     * type to the direction that matters.
     */
    public function assess(array $sections): array
    {
        $findings = [];

        foreach ($this->vitalGroups($sections) as $group) {
            $type = (string) ($group['type'] ?? '');
            $latest = is_array($group['latest'] ?? null) ? $group['latest'] : [];

            $max = $this->number($group['max'] ?? null) ?? $this->number($latest['value'] ?? null);
            $min = $this->number($group['min'] ?? null);

            $critical = match ($type) {
                'blood_pressure' => $this->bloodPressureIsCritical($max, $latest),
                'heart_rate' => $this->highOrLow(
                    $max,
                    $min,
                    config('dashboard.thresholds.heart_rate.critical_high'),
                    config('dashboard.thresholds.heart_rate.critical_low'),
                ),
                'blood_glucose' => $this->highOrLow(
                    $max,
                    $min,
                    config('dashboard.thresholds.blood_glucose.critical_high'),
                    config('dashboard.thresholds.blood_glucose.critical_low'),
                ),
                'spo2' => $this->atOrBelow($min ?? $this->number($latest['value'] ?? null), config('dashboard.thresholds.spo2.critical_low')),
                default => false,
            };

            if ($critical) {
                $findings[] = $type;
            }
        }

        return array_values(array_unique($findings));
    }

    public function ensureAdvice(string $content, array $sections): ValidatedOutput
    {
        $critical = $this->assess($sections);

        if ($critical === [] || $this->hasAdvice($content)) {
            return new ValidatedOutput($content, false, $critical);
        }

        $advice = trim((string) config('ai.safety.advice', ''));

        if ($advice === '') {
            return new ValidatedOutput($content, false, $critical);
        }

        return new ValidatedOutput(
            rtrim($content)."\n\n".$advice,
            true,
            $critical,
        );
    }

    private function bloodPressureIsCritical(?float $systolic, array $latest): bool
    {
        $criticalSystolic = $this->number(config('dashboard.thresholds.blood_pressure_systolic.critical'));
        $criticalDiastolic = $this->number(config('dashboard.thresholds.blood_pressure_diastolic.critical'));
        $diastolic = $this->number($latest['secondary_value'] ?? null);

        return $this->atOrAbove($systolic, $criticalSystolic)
            || $this->atOrAbove($diastolic, $criticalDiastolic);
    }

    private function highOrLow(?float $max, ?float $min, mixed $criticalHigh, mixed $criticalLow): bool
    {
        return $this->atOrAbove($max, $this->number($criticalHigh))
            || $this->atOrBelow($min, $this->number($criticalLow));
    }

    private function atOrAbove(?float $value, ?float $threshold): bool
    {
        return $value !== null && $threshold !== null && $value >= $threshold;
    }

    private function atOrBelow(?float $value, ?float $threshold): bool
    {
        return $value !== null && $threshold !== null && $value <= $threshold;
    }

    /**
     * The vitals section is a list of aggregates; accept a single group too so
     * a caller cannot accidentally skip the check by changing the shape.
     *
     * @return list<array<string, mixed>>
     */
    private function vitalGroups(array $sections): array
    {
        $vitals = $sections['vitals'] ?? null;

        if (! is_array($vitals)) {
            return [];
        }

        if (isset($vitals['type'])) {
            return [$vitals];
        }

        $groups = [];

        foreach ($vitals as $group) {
            if (is_array($group)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function hasAdvice(string $content): bool
    {
        foreach ((array) config('ai.safety.markers', []) as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($content, $marker)) {
                return true;
            }
        }

        return false;
    }
}
