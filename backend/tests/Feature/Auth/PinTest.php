<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PIN-T01..T03 — hashed storage, lockout after five failures, weak PINs.
 */
class PinTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_pin_t01_setup_hashes_the_pin_and_verification_succeeds(): void
    {
        $this->postJson('/api/v1/auth/pin/setup', ['pin' => '135790'])
            ->assertStatus(204);

        $this->user->refresh();

        $this->assertNotNull($this->user->pin_hash);
        $this->assertNotSame('135790', $this->user->pin_hash);
        $this->assertTrue(Hash::check('135790', $this->user->pin_hash));

        $this->postJson('/api/v1/auth/pin/verify', ['pin' => '135790'])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->user->refresh();

        $this->assertSame(0, $this->user->pin_failed_attempts);
        $this->assertNull($this->user->pin_locked_until);
    }

    public function test_pin_t02_five_failures_lock_the_sixth_attempt(): void
    {
        $this->postJson('/api/v1/auth/pin/setup', ['pin' => '135790'])->assertStatus(204);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/pin/verify', ['pin' => '246810'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/pin/verify', ['pin' => '246810'])
            ->assertStatus(423)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'locked');
    }

    public function test_pin_t02_correct_pin_is_also_locked_out(): void
    {
        $this->postJson('/api/v1/auth/pin/setup', ['pin' => '135790'])->assertStatus(204);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/pin/verify', ['pin' => '246810'])
                ->assertStatus(422);
        }

        // Correct PIN, but the account is locked (423).
        $this->postJson('/api/v1/auth/pin/verify', ['pin' => '135790'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'locked');
    }

    public function test_pin_t03_weak_pins_are_rejected(): void
    {
        foreach (['123456', '000000', '111111', '654321'] as $pin) {
            $this->postJson('/api/v1/auth/pin/setup', ['pin' => $pin])
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation');
        }

        $this->user->refresh();
        $this->assertNull($this->user->pin_hash);
    }
}
