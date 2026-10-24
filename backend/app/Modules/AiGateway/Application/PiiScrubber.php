<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\PiiScrubException;
use App\Modules\AiGateway\Domain\ScrubbedContext;

/**
 * Step 3 — PII Scrubber & Pseudonymizer (`.ai/08-AI-GATEWAY.md` section 2).
 *
 * Rules implemented here:
 *
 *  - person names become stable pseudonyms (`บุคคล A`, `บุคคล B`, ...);
 *  - national IDs, phone numbers, e-mail addresses and LINE ids are removed;
 *  - address-like values are replaced as a whole (never partially exposed);
 *  - facility names become `Facility_A`, `Facility_B`, ...;
 *  - if a rule cannot be applied confidently, the whole attempt is refused
 *    instead of sending the context (AIG-003, fail closed).
 *
 * Medication names are intentionally kept: the blueprint needs them for a
 * useful summary.
 */
final class PiiScrubber
{
    private const REDACTED = '[REDACTED]';

    private const REDACTED_ADDRESS = '[REDACTED_ADDRESS]';

    /** Keys whose value is always an address, regardless of the text. */
    private const ADDRESS_KEYS = ['address', 'home_address', 'postal_address'];

    /** Keys whose value is always removed. */
    private const REDACTED_KEYS = [
        'phone',
        'phone_number',
        'mobile',
        'mobile_number',
        'tel',
        'telephone',
        'email',
        'email_address',
        'national_id',
        'national_id_number',
        'id_card',
        'citizen_id',
        'line_id',
    ];

    /** Keys whose value is a person name. `name` is deliberately absent. */
    private const PERSON_NAME_KEYS = [
        'display_name',
        'full_name',
        'first_name',
        'last_name',
        'patient_name',
        'provider_name',
    ];

    /** @var list<string> */
    private const ADDRESS_MARKERS = [
        'ที่อยู่',
        'ถนน',
        'ซอย',
        'หมู่',
        'ตำบล',
        'อำเภอ',
        'จังหวัด',
        'address',
        'street',
        'road',
        'soi',
        'moo',
        'tambon',
        'amphoe',
        'province',
        'postal',
    ];

    /** 13 digits, with optional single separators (Thai national ID). */
    private const NATIONAL_ID_PATTERN = '/(?<!\d)\d(?:[-\s]?\d){12}(?!\d)/u';

    /** Thai mobile/landline forms, including +66. */
    private const PHONE_PATTERN = '/(?<!\d)(?:\+?66|0)(?:[-\s]?\d){8,9}(?!\d)/u';

    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    /** LINE ids start with @ and are at least 3 characters. */
    private const LINE_ID_PATTERN = '/(?<![\p{L}\p{N}])@[A-Za-z0-9._\-]{3,}/u';

    /**
     * @param  array<string, mixed>  $sections
     * @param  list<string>  $personNames
     */
    public function scrub(array $sections, array $personNames): ScrubbedContext
    {
        $state = [
            'pseudonyms' => $this->buildPseudonyms($personNames),
            'next_pseudonym' => 0,
            'facilities' => [],
            'next_facility' => 0,
        ];

        // Start the counter after the known names so a later unknown name gets
        // the next free letter rather than reusing A.
        $state['next_pseudonym'] = count($state['pseudonyms']);

        $scrubbed = $this->scrubValue($sections, $state);

        $this->assertNoResidualPii($scrubbed);

        return new ScrubbedContext($scrubbed, count($state['pseudonyms']));
    }

    /**
     * @param  list<string>  $personNames
     * @return array<string, string>
     */
    private function buildPseudonyms(array $personNames): array
    {
        $map = [];

        foreach ($personNames as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = trim($name);

            if (mb_strlen($name) < 2 || isset($map[$name])) {
                continue;
            }

            $map[$name] = $this->pseudonymForIndex(count($map));
        }

        return $map;
    }

    /**
     * @param  array{pseudonyms: array<string, string>, next_pseudonym: int, facilities: array<string, string>, next_facility: int}  $state
     */
    private function scrubValue(mixed $value, array &$state): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = is_string($key)
                    ? $this->scrubKeyedValue($key, $item, $state)
                    : $this->scrubValue($item, $state);
            }

            return $result;
        }

        return is_string($value) ? $this->scrubString($value, $state) : $value;
    }

    /**
     * @param  array{pseudonyms: array<string, string>, next_pseudonym: int, facilities: array<string, string>, next_facility: int}  $state
     */
    private function scrubKeyedValue(string $key, mixed $value, array &$state): mixed
    {
        $normalisedKey = strtolower($key);

        if (in_array($normalisedKey, self::ADDRESS_KEYS, true)) {
            return $this->isBlank($value) ? $value : self::REDACTED_ADDRESS;
        }

        if (in_array($normalisedKey, self::REDACTED_KEYS, true)) {
            return $this->isBlank($value) ? $value : self::REDACTED;
        }

        if ($normalisedKey === 'facility') {
            if (! is_string($value) || trim($value) === '') {
                return $value;
            }

            $facility = trim($value);

            if (! isset($state['facilities'][$facility])) {
                $state['facilities'][$facility] = 'Facility_'.$this->letterForIndex($state['next_facility']);
                $state['next_facility']++;
            }

            return $state['facilities'][$facility];
        }

        if (in_array($normalisedKey, self::PERSON_NAME_KEYS, true)) {
            if (! is_string($value) || trim($value) === '') {
                return $value;
            }

            return $this->pseudonymizeText($value, $state);
        }

        return $this->scrubValue($value, $state);
    }

    /**
     * @param  array{pseudonyms: array<string, string>, next_pseudonym: int, facilities: array<string, string>, next_facility: int}  $state
     */
    private function scrubString(string $value, array &$state): string
    {
        if (trim($value) === '') {
            return $value;
        }

        if ($this->containsAddressMarker($value)) {
            return self::REDACTED_ADDRESS;
        }

        $value = $this->replaceKnownNames($value, $state);
        $value = preg_replace(self::NATIONAL_ID_PATTERN, self::REDACTED, $value) ?? $value;
        $value = preg_replace(self::PHONE_PATTERN, self::REDACTED, $value) ?? $value;
        $value = preg_replace(self::EMAIL_PATTERN, self::REDACTED, $value) ?? $value;
        $value = preg_replace(self::LINE_ID_PATTERN, self::REDACTED, $value) ?? $value;

        return $value;
    }

    /**
     * @param  array{pseudonyms: array<string, string>, next_pseudonym: int, facilities: array<string, string>, next_facility: int}  $state
     */
    private function pseudonymizeText(string $value, array &$state): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || str_starts_with($trimmed, 'บุคคล ')) {
            return $value;
        }

        if (isset($state['pseudonyms'][$trimmed])) {
            return $state['pseudonyms'][$trimmed];
        }

        foreach ($state['pseudonyms'] as $name => $pseudonym) {
            if (mb_strtolower($name) === mb_strtolower($trimmed)) {
                return $pseudonym;
            }
        }

        // An unknown person name on a name field is still a name: replace the
        // whole field with the next stable pseudonym rather than leaking a
        // surname through a partial match.
        $state['pseudonyms'][$trimmed] = $this->pseudonymForIndex($state['next_pseudonym']);
        $state['next_pseudonym']++;

        return $state['pseudonyms'][$trimmed];
    }

    /**
     * @param  array{pseudonyms: array<string, string>, next_pseudonym: int, facilities: array<string, string>, next_facility: int}  $state
     */
    private function replaceKnownNames(string $value, array &$state): string
    {
        $names = array_keys($state['pseudonyms']);

        // Longest first so "Somchai Patient" wins over a shorter overlapping
        // token.
        usort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($names as $name) {
            $pattern = '/'.preg_quote($name, '/').'/iu';
            $value = preg_replace($pattern, $state['pseudonyms'][$name], $value) ?? $value;
        }

        return $value;
    }

    private function containsAddressMarker(string $value): bool
    {
        foreach (self::ADDRESS_MARKERS as $marker) {
            if (mb_stripos($value, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared by step 5 so the same PII vocabulary is used on input and output.
     *
     * The redaction placeholders themselves are removed before scanning: they
     * deliberately contain words such as "address", and treating the marker we
     * just wrote as residual PII would make every address-bearing context fail
     * closed forever. Removing the placeholder can only merge its neighbours,
     * which is stricter (more likely to be flagged), not looser.
     */
    public function hasResidualPii(string $text): bool
    {
        $candidate = str_replace([self::REDACTED_ADDRESS, self::REDACTED], '', $text);

        foreach ([
            self::NATIONAL_ID_PATTERN,
            self::PHONE_PATTERN,
            self::EMAIL_PATTERN,
            self::LINE_ID_PATTERN,
        ] as $pattern) {
            if (preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return $this->containsAddressMarker($candidate);
    }

    private function assertNoResidualPii(mixed $scrubbed): void
    {
        $json = json_encode($scrubbed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($json)) {
            throw PiiScrubException::because('pii_scrub_encoding_failed');
        }

        if ($this->hasResidualPii($json)) {
            throw PiiScrubException::because('residual_pii');
        }
    }

    private function pseudonymForIndex(int $index): string
    {
        return 'บุคคล '.$this->letterForIndex($index);
    }

    private function letterForIndex(int $index): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        if ($index < 26) {
            return $letters[$index];
        }

        return $letters[intdiv($index, 26) - 1].$letters[$index % 26];
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
