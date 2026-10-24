<?php

namespace App\Modules\AiGateway\Application\Extraction;

use App\Modules\AiGateway\Application\InjectionGuard;
use App\Modules\AiGateway\Domain\PromptEnvelope;
use App\Modules\HealthRecord\Domain\MeasurementRules;
use App\Modules\HealthRecord\Domain\MeasurementType;
use App\Modules\HealthRecord\Domain\MedicationPlanRules;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validates a provider's extraction JSON before it becomes a draft (TASK P-3).
 *
 * The provider is never trusted:
 *  - only the keys in the closed per-kind schema survive;
 *  - every value is type-checked and range-checked with the same domain rules
 *    the HTTP write endpoints use (`MeasurementRules`, `MedicationPlanRules`);
 *  - an out-of-range value is dropped and reported in `warnings`, never
 *    returned as fact;
 *  - instruction-looking text is detected by the existing `InjectionGuard` and
 *    neutralised before it is shown.
 *
 * Nothing here writes to the database.
 */
final class ExtractionValidator
{
    public function __construct(private readonly InjectionGuard $injection) {}

    /**
     * @param  array<string, mixed>  $data  the decoded provider object
     * @return array{fields: array<string, mixed>, warnings: list<string>, confidence: float}
     */
    public function validate(string $kind, array $data): array
    {
        $warnings = [];

        $fields = $kind === ExtractionPromptBuilder::KIND_MEDICATION
            ? $this->medicationFields($data, $warnings)
            : $this->deviceFields($data, $warnings);

        $confidence = $this->confidence($data['confidence'] ?? null);

        [$fields, $neutralised] = $this->neutraliseInstructions($fields);

        if ($neutralised) {
            $warnings[] = 'injection_neutralised';
        }

        return [
            'fields' => $fields,
            'warnings' => array_values(array_unique($warnings)),
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function medicationFields(array $data, array &$warnings): array
    {
        $fields = [];

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            if (! is_string($data['name'])
                || isset($this->medicationErrors(['name' => $data['name']])['name'])) {
                $warnings[] = 'name_invalid';
            } else {
                $fields['name'] = trim($data['name']);
            }
        }

        if (array_key_exists('dosage', $data) && $data['dosage'] !== null) {
            if (! is_string($data['dosage'])
                || isset($this->medicationErrors(['dosage' => $data['dosage']])['dosage'])) {
                $warnings[] = 'dosage_invalid';
            } else {
                $fields['dosage'] = trim($data['dosage']);
            }
        }

        if (array_key_exists('doses_per_day', $data) && $data['doses_per_day'] !== null) {
            $doses = $data['doses_per_day'];
            $candidate = $this->integerLike($doses);

            if ($candidate === null) {
                $warnings[] = 'doses_per_day_out_of_range';
            } else {
                $times = $candidate >= MedicationPlanRules::MIN_DOSES_PER_DAY
                    && $candidate <= MedicationPlanRules::MAX_DOSES_PER_DAY
                    ? $this->dummyTimes($candidate)
                    : ['08:00'];

                $errors = $this->medicationErrors([
                    'doses_per_day' => $candidate,
                    'schedule_times' => $times,
                ]);

                if (isset($errors['doses_per_day'])) {
                    $warnings[] = 'doses_per_day_out_of_range';
                } else {
                    $fields['doses_per_day'] = $candidate;
                }
            }
        }

        if (array_key_exists('schedule_times', $data) && $data['schedule_times'] !== null) {
            $times = $data['schedule_times'];

            if (! is_array($times) || $times === [] || ! array_is_list($times)) {
                $warnings[] = 'schedule_times_invalid';
            } else {
                $count = count($times);
                $errors = $this->medicationErrors([
                    'schedule_times' => $times,
                    'doses_per_day' => $count >= MedicationPlanRules::MIN_DOSES_PER_DAY
                        && $count <= MedicationPlanRules::MAX_DOSES_PER_DAY
                        ? $count
                        : 1,
                ]);

                if (isset($errors['schedule_times'])) {
                    $warnings[] = 'schedule_times_invalid';
                } else {
                    $fields['schedule_times'] = array_map(
                        static fn (mixed $time): string => trim((string) $time),
                        array_values($times),
                    );
                }
            }
        }

        if (array_key_exists('instructions', $data) && $data['instructions'] !== null) {
            if (! is_string($data['instructions'])
                || isset($this->medicationErrors(['instructions' => $data['instructions']])['instructions'])) {
                $warnings[] = 'instructions_invalid';
            } else {
                $fields['instructions'] = trim($data['instructions']);
            }
        }

        // The domain invariant is one schedule slot per daily dose. Each field
        // was range-checked independently above; a disagreement is still not
        // safe to surface as a schedule.
        if (isset($fields['doses_per_day'], $fields['schedule_times'])
            && count($fields['schedule_times']) !== $fields['doses_per_day']) {
            $warnings[] = 'schedule_times_invalid';
            unset($fields['schedule_times']);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function deviceFields(array $data, array &$warnings): array
    {
        $type = is_string($data['type'] ?? null)
            ? MeasurementType::tryFrom($data['type'])
            : null;

        if ($type === null) {
            $warnings[] = 'type_not_supported';

            return [];
        }

        $fields = ['type' => $type->value];
        $errors = $this->measurementErrors($type, $data);

        if (array_key_exists('value', $data) && $data['value'] !== null) {
            if (isset($errors['value']) || ! is_numeric($data['value'])) {
                $warnings[] = 'value_out_of_range';
            } else {
                $fields['value'] = (float) $data['value'];
            }
        }

        if (array_key_exists('secondary_value', $data) && $data['secondary_value'] !== null) {
            if (isset($errors['secondary_value']) || ! is_numeric($data['secondary_value'])) {
                $warnings[] = 'secondary_value_invalid';
            } else {
                $fields['secondary_value'] = (float) $data['secondary_value'];
            }
        }

        if (array_key_exists('unit', $data) && $data['unit'] !== null) {
            $unit = is_string($data['unit']) ? trim($data['unit']) : null;

            if ($unit === null || $unit !== $type->unit()) {
                $warnings[] = 'unit_mismatch';
            } else {
                $fields['unit'] = $unit;
            }
        }

        if (array_key_exists('measured_at', $data) && $data['measured_at'] !== null && $data['measured_at'] !== '') {
            if (isset($errors['recorded_at'])) {
                $warnings[] = 'measured_at_invalid';
            } else {
                try {
                    $fields['measured_at'] = Carbon::parse((string) $data['measured_at'])->toIso8601String();
                } catch (Throwable) {
                    $warnings[] = 'measured_at_invalid';
                }
            }
        }

        return $fields;
    }

    /**
     * Run the medication domain rules with valid defaults, overriding only the
     * field under test. This keeps the extraction gate identical to the write
     * endpoint instead of re-implementing the ranges.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, list<string>>
     */
    private function medicationErrors(array $overrides): array
    {
        $input = array_merge([
            'name' => 'Medication',
            'dosage' => '1 tablet',
            'form' => 'tablet',
            'doses_per_day' => 1,
            'schedule_times' => ['08:00'],
            'starts_on' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides);

        try {
            MedicationPlanRules::forCreate($input);

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    private function measurementErrors(MeasurementType $type, array $data): array
    {
        $input = [
            'type' => $type->value,
            'value' => $data['value'] ?? null,
            'secondary_value' => $data['secondary_value'] ?? null,
            // The extracted schema has no glucose context; `random` is used
            // only to reach the same range checks. It is never surfaced.
            'context' => 'random',
            'recorded_at' => $data['measured_at'] ?? 'now',
            'source' => 'device',
        ];

        try {
            MeasurementRules::forCreate($input);

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    /**
     * @return list<string>
     */
    private function dummyTimes(int $count): array
    {
        $times = [];

        for ($index = 0; $index < $count; $index++) {
            // 08:00, 09:00, ... — valid HH:MM slots, only used for a range check.
            $times[] = str_pad((string) (8 + min($index, 15)), 2, '0', STR_PAD_LEFT).':00';
        }

        return $times;
    }

    /**
     * Match `MedicationPlanRules`' own integer coercion: an int, an integral
     * float or a digit-only string. Anything else is a type violation.
     */
    private function integerLike(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    /**
     * Detect instruction-looking text in the extracted fields with the same
     * guard used for record blocks, then replace the phrase so it cannot be
     * read as an instruction. The model's text is always data.
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function neutraliseInstructions(array $fields): array
    {
        $text = implode("\n", $this->stringValues($fields));

        if ($text === '') {
            return [$fields, false];
        }

        $verdict = $this->injection->inspect(new PromptEnvelope(
            system: '',
            untrustedBlocks: [[
                'type' => 'extracted_text',
                'content' => $text,
            ]],
            structuredContext: [],
            locale: 'th',
            maxTokens: 0,
            temperature: 0.0,
        ));

        if (! $verdict->detected()) {
            return [$fields, false];
        }

        $patterns = array_values(array_filter(
            (array) config('ai.injection.patterns', []),
            static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));

        $replacement = (string) config('ai.injection.replacement', '[NEUTRALIZED_INSTRUCTION]');

        return [$this->replaceStrings($fields, $patterns, $replacement), true];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function stringValues(array $value): array
    {
        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            } elseif (is_array($item)) {
                $strings = array_merge($strings, $this->stringValues($item));
            }
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $patterns
     * @return array<string, mixed>
     */
    private function replaceStrings(array $fields, array $patterns, string $replacement): array
    {
        foreach ($fields as $key => $value) {
            if (is_string($value)) {
                $fields[$key] = $patterns === []
                    ? $value
                    : str_ireplace($patterns, $replacement, $value);
            } elseif (is_array($value)) {
                $fields[$key] = $this->replaceStrings($value, $patterns, $replacement);
            }
        }

        return $fields;
    }
}
