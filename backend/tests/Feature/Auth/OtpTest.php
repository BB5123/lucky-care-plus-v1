<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Accounts\Contracts\OtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeOtpSender;
use Tests\TestCase;

/**
 * OTP-T01..T03 — request/verify, attempt exhaustion, expiry, hash-only storage.
 */
class OtpTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0812345678';

    private User $user;

    private FakeOtpSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->sender = new FakeOtpSender;

        $this->app->instance(OtpSender::class, $this->sender);
    }

    public function test_otp_t01_request_then_verify_marks_the_phone_verified(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])
            ->assertStatus(202)
            ->assertJsonMissing(['code' => $this->sender->lastCode]);

        $code = (string) $this->sender->lastCode;

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame(self::PHONE, $this->sender->lastDestination);

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.phone', self::PHONE);

        $this->user->refresh();

        $this->assertSame(self::PHONE, $this->user->phone);
        $this->assertNotNull($this->user->phone_verified_at);

        // Only the SHA-256 hash is stored; the plaintext never reaches the DB.
        $stored = DB::table('otp_codes')->value('code_hash');
        $this->assertIsString($stored);
        $this->assertNotSame($code, $stored);
        $this->assertSame(hash('sha256', $code), $stored);
    }

    public function test_otp_t02_five_wrong_attempts_exhaust_the_code(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])->assertStatus(202);

        $correct = (string) $this->sender->lastCode;
        $wrong = $correct === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', [
                'phone' => self::PHONE,
                'code' => $wrong,
            ])->assertStatus(422);
        }

        // The sixth request is the correct code, but the code is exhausted.
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => $correct,
        ])->assertStatus(422);
    }

    public function test_otp_t03_expired_code_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])->assertStatus(202);

        $code = (string) $this->sender->lastCode;

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => $code,
        ])->assertStatus(422);
    }
}
