<?php

namespace App\Modules\HealthRecord\Domain;

/**
 * The closed vocabulary of measurement types supported by the Health Vault.
 *
 * Ranges and units are normative for the Phase 1 slice (TASK P1.1 §2). A type
 * outside this enum is rejected with 422; it is never silently stored.
 */
enum MeasurementType: string
{
    case BloodPressure = 'blood_pressure';
    case BloodGlucose = 'blood_glucose';
    case HeartRate = 'heart_rate';
    case Weight = 'weight';
    case Spo2 = 'spo2';
    case Temperature = 'temperature';

    public function unit(): string
    {
        return match ($this) {
            self::BloodPressure => 'mmHg',
            self::BloodGlucose => 'mg/dL',
            self::HeartRate => 'bpm',
            self::Weight => 'kg',
            self::Spo2 => '%',
            self::Temperature => '°C',
        };
    }

    public function minimum(): float
    {
        return match ($this) {
            self::BloodPressure => 50.0,
            self::BloodGlucose => 20.0,
            self::HeartRate => 20.0,
            self::Weight => 1.0,
            self::Spo2 => 50.0,
            self::Temperature => 30.0,
        };
    }

    public function maximum(): float
    {
        return match ($this) {
            self::BloodPressure => 300.0,
            self::BloodGlucose => 800.0,
            self::HeartRate => 250.0,
            self::Weight => 400.0,
            self::Spo2 => 100.0,
            self::Temperature => 45.0,
        };
    }

    public function requiresSecondaryValue(): bool
    {
        return $this === self::BloodPressure;
    }

    public function requiresContext(): bool
    {
        return $this === self::BloodGlucose;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
