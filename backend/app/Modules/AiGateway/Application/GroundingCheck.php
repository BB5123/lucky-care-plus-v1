<?php

namespace App\Modules\AiGateway\Application;

use App\Modules\AiGateway\Domain\InvalidOutputException;

/**
 * Grounding check for step 5 (`.ai/08-AI-GATEWAY.md` section 6).
 *
 * A provider may only repeat numbers that exist in the structured context it
 * received. Every numeric token in the answer is looked up in that context; a
 * number that is not there means the model invented a value (or copied one
 * from outside the authorised window), so the whole answer is discarded
 * (fail closed, `error_code = ungrounded_output`).
 *
 * Interpretation notes:
 *  - numbers are compared after normalising thousands separators and trailing
 *    `.0` (`1,234.0` and `1234` are the same value);
 *  - the context is the *scrubbed* structure, i.e. exactly what was placed in
 *    the untrusted blocks, so scrubbing cannot hide a value from the check;
 *  - `config('ai.grounding.allowed_tokens')` is an explicitly reviewed
 *    allow-list and ships empty.
 */
final class GroundingCheck
{
    /** Digits with an optional decimal/thousands part. */
    private const NUMBER_PATTERN = '/\d+(?:[.,]\d+)?/u';

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidOutputException when the answer contains a number that is not in the context
     */
    public function assertGrounded(string $content, array $context): void
    {
        $allowed = $this->tokens($context);

        foreach ($this->numbers($content) as $number) {
            if (! in_array($number, $allowed, true)) {
                throw InvalidOutputException::because('ungrounded_output');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    public function tokens(array $context): array
    {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($json)) {
            return [];
        }

        $tokens = $this->numbers($json);

        foreach ($this->allowedTokens() as $token) {
            $tokens[] = $this->normalise($token);
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string>
     */
    private function numbers(string $text): array
    {
        $matches = [];

        if (preg_match_all(self::NUMBER_PATTERN, $text, $matches) < 1) {
            return [];
        }

        return array_values(array_unique(array_map(
            $this->normalise(...),
            $matches[0],
        )));
    }

    private function normalise(string $number): string
    {
        $normalised = str_replace([',', ' '], '', $number);

        if (str_ends_with($normalised, '.0')) {
            $normalised = substr($normalised, 0, -2);
        }

        $normalised = ltrim($normalised, '+');

        return $normalised === '' ? '0' : $normalised;
    }

    /**
     * @return list<string>
     */
    private function allowedTokens(): array
    {
        $tokens = [];

        foreach ((array) config('ai.grounding.allowed_tokens', []) as $token) {
            if (is_string($token) || is_int($token) || is_float($token)) {
                $tokens[] = (string) $token;
            }
        }

        return $tokens;
    }
}
