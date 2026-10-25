<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints a SHA-256 fingerprint of the application keys (gap 4: APP_KEY escrow).
 *
 * Encrypted columns (health notes, document paths and names, OAuth-style
 * secrets) are unreadable without the key that wrote them. The key itself must
 * live in a secret store, and this command is how an operator proves - after a
 * restore or a migration - that the key they recovered is the right one:
 *
 *   old host:  php artisan app:key-fingerprint
 *   new host:  php artisan app:key-fingerprint     # must match
 *
 * It prints only the fingerprint, the cipher and the key length. The key, its
 * base64 form and the previous keys are never printed: the fingerprint is
 * one-way, and a shortened fingerprint would still be a secret leak vector if it
 * were derived from a weak input. The same value is safe to paste into a runbook.
 *
 * Previous keys (APP_PREVIOUS_KEYS, used during rotation) get one fingerprint
 * each, so a rotation can be verified key by key.
 */
final class AppKeyFingerprintCommand extends Command
{
    protected $signature = 'app:key-fingerprint
        {--json : Machine-readable output}';

    protected $description = 'Print a SHA-256 fingerprint of APP_KEY (and any previous keys) without revealing them';

    public function handle(): int
    {
        $key = (string) config('app.key');

        if ($key === '') {
            $this->error('APP_KEY is not set.');

            return self::FAILURE;
        }

        $primary = $this->fingerprint($key);

        if ($primary === null) {
            $this->error('APP_KEY is not readable: it must be a base64 key or a raw AES key of the configured length.');

            return self::FAILURE;
        }

        $previous = [];

        foreach ((array) config('app.previous_keys', []) as $index => $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $fingerprint = $this->fingerprint($candidate);

            $previous[] = [
                'index' => $index + 1,
                'fingerprint' => $fingerprint ?? 'unreadable',
            ];
        }

        $payload = [
            'app_key' => $primary,
            'cipher' => (string) config('app.cipher'),
            'key_length' => $this->keyLength($key),
            'previous_keys' => $previous,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Item', 'Value'],
            [
                ['APP_KEY fingerprint (sha256)', $primary],
                ['cipher', $payload['cipher']],
                ['key length', (string) $payload['key_length']],
                ...array_map(
                    static fn (array $row): array => ["previous key #{$row['index']}", $row['fingerprint']],
                    $previous,
                ),
            ],
        );

        $this->line('Compare this fingerprint on the restored host: the key is only correct if the value matches.');
        $this->line('The key itself is never printed; escrow it in a secret store, not in this output.');

        return self::SUCCESS;
    }

    /**
     * SHA-256 of the raw key bytes. Laravel's `base64:` prefix is decoded so
     * the same key always gives the same fingerprint regardless of how it is
     * written in the environment.
     */
    private function fingerprint(string $key): ?string
    {
        $raw = $this->rawKey($key);

        return $raw === null ? null : hash('sha256', $raw);
    }

    private function keyLength(string $key): int
    {
        $raw = $this->rawKey($key);

        return $raw === null ? 0 : strlen($raw);
    }

    private function rawKey(string $key): ?string
    {
        $key = trim($key);

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) && $decoded !== '' ? $decoded : null;
        }

        return $key === '' ? null : $key;
    }
}
