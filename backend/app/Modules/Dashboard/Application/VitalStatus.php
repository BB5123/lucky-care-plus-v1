<?php

namespace App\Modules\Dashboard\Application;

/**
 * Maps one reading to the dashboard's presentation status and the internal
 * severity used by the status ring and alerts.
 *
 * Thresholds come from `config/dashboard.php` and are illustrative until a
 * clinical review (the config file says so explicitly). Types without a
 * configured threshold (weight, temperature) are always `normal`.
 *
 * `status` values are the API-facing vocabulary; `severity` is internal:
 *   - critical → any critical threshold crossed
 *   - warning  → any warning threshold crossed
 *   - normal   → otherwise
 *
 * `direction` exists so a translation key can distinguish high from low
 * without putting a health value in free text (N-02).
 */
final readonly class VitalStatus
{
    public const SEVERITY_NORMAL = 'normal';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    private function __construct(
        public string $severity,
        public string $status,
        public ?string $direction,
    ) {}

    public static function normal(): self
    {
        return new self(self::SEVERITY_NORMAL, 'normal', null);
    }

    public static function classify(string $type, float $value, ?float $secondary): self
    {
        $thresholds = config('dashboard.thresholds', []);

        return match ($type) {
            'blood_pressure' => self::bloodPressure($value, $secondary, $thresholds),
            'blood_glucose' => self::glucose($value, $thresholds),
            'spo2' => self::spo2($value, $thresholds),
            'heart_rate' => self::heartRate($value, $thresholds),
            default => self::normal(),
        };
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    public function isWarning(): bool
    {
        return $this->severity === self::SEVERITY_WARNING;
    }

    /**
     * @param  array<string, array<string, int|float>>  $thresholds
     */
    private static function bloodPressure(float $systolic, ?float $diastolic, array $thresholds): self
    {
        $criticalSystolic = (float) ($thresholds['blood_pressure_systolic']['critical'] ?? 160);
        $warningSystolic = (float) ($thresholds['blood_pressure_systolic']['warning'] ?? 140);
        $criticalDiastolic = (float) ($thresholds['blood_pressure_diastolic']['critical'] ?? 100);
        $warningDiastolic = (float) ($thresholds['blood_pressure_diastolic']['warning'] ?? 90);

        if ($systolic >= $criticalSystolic || ($diastolic !== null && $diastolic >= $criticalDiastolic)) {
            return new self(self::SEVERITY_CRITICAL, 'critical', 'high');
        }

        if ($systolic >= $warningSystolic || ($diastolic !== null && $diastolic >= $warningDiastolic)) {
            return new self(self::SEVERITY_WARNING, 'high', 'high');
        }

        return self::normal();
    }

    /**
     * @param  array<string, array<string, int|float>>  $thresholds
     */
    private static function glucose(float $value, array $thresholds): self
    {
        $criticalHigh = (float) ($thresholds['blood_glucose']['critical_high'] ?? 250);
        $criticalLow = (float) ($thresholds['blood_glucose']['critical_low'] ?? 70);
        $warningHigh = (float) ($thresholds['blood_glucose']['warning_high'] ?? 180);

        if ($value >= $criticalHigh) {
            return new self(self::SEVERITY_CRITICAL, 'critical', 'high');
        }

        if ($value <= $criticalLow) {
            return new self(self::SEVERITY_CRITICAL, 'critical', 'low');
        }

        if ($value >= $warningHigh) {
            return new self(self::SEVERITY_WARNING, 'high', 'high');
        }

        return self::normal();
    }

    /**
     * @param  array<string, array<string, int|float>>  $thresholds
     */
    private static function spo2(float $value, array $thresholds): self
    {
        $criticalLow = (float) ($thresholds['spo2']['critical_low'] ?? 92);

        if ($value <= $criticalLow) {
            return new self(self::SEVERITY_CRITICAL, 'critical', 'low');
        }

        return self::normal();
    }

    /**
     * @param  array<string, array<string, int|float>>  $thresholds
     */
    private static function heartRate(float $value, array $thresholds): self
    {
        $criticalHigh = (float) ($thresholds['heart_rate']['critical_high'] ?? 130);
        $criticalLow = (float) ($thresholds['heart_rate']['critical_low'] ?? 50);
        $warningHigh = (float) ($thresholds['heart_rate']['warning_high'] ?? 100);

        if ($value >= $criticalHigh) {
            return new self(self::SEVERITY_CRITICAL, 'critical', 'high');
        }

        if ($value <= $criticalLow) {
            return new self(self::SEVERITY_CRITICAL, 'critical', 'low');
        }

        if ($value >= $warningHigh) {
            return new self(self::SEVERITY_WARNING, 'high', 'high');
        }

        return self::normal();
    }
}
