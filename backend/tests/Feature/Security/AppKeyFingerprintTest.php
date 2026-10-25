<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * KEY-T01..T03 - the APP_KEY fingerprint used for escrow and restore checks.
 *
 * The command exists so an operator can prove the recovered key is the right
 * one without ever printing it. These tests pin the contract: the fingerprint is
 * sha256 over the raw key bytes, the key and its base64 form never appear in the
 * output, and a missing key is a failure.
 */
class AppKeyFingerprintTest extends TestCase
{
    private function key(int $byte): string
    {
        return 'base64:'.base64_encode(str_repeat(chr($byte), 32));
    }

    public function test_key_t01_the_fingerprint_is_sha256_of_the_raw_key(): void
    {
        $raw = str_repeat('a', 32);
        config([
            'app.key' => 'base64:'.base64_encode($raw),
            'app.cipher' => 'AES-256-CBC',
            'app.previous_keys' => [],
        ]);

        Artisan::call('app:key-fingerprint');
        $output = Artisan::output();

        $this->assertStringContainsString(hash('sha256', $raw), $output);
        $this->assertStringNotContainsString($raw, $output);
        $this->assertStringNotContainsString(base64_encode($raw), $output);
        $this->assertSame(0, Artisan::call('app:key-fingerprint'));
    }

    public function test_key_t02_previous_keys_get_their_own_fingerprints(): void
    {
        config([
            'app.key' => $this->key(1),
            'app.cipher' => 'AES-256-CBC',
            'app.previous_keys' => [$this->key(2)],
        ]);

        Artisan::call('app:key-fingerprint', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(hash('sha256', str_repeat(chr(1), 32)), $payload['app_key']);
        $this->assertSame('AES-256-CBC', $payload['cipher']);
        $this->assertSame(32, $payload['key_length']);
        $this->assertSame(
            hash('sha256', str_repeat(chr(2), 32)),
            $payload['previous_keys'][0]['fingerprint'],
        );
    }

    public function test_key_t03_a_missing_key_fails(): void
    {
        config(['app.key' => '']);

        $this->artisan('app:key-fingerprint')->assertFailed();
    }
}
