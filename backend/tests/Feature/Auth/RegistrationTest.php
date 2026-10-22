<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REG-T01, REG-T02 — account creation and email uniqueness.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reg_t01_register_returns_201_and_the_token_works_on_me(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Somchai Jaidee',
            'email' => 'somchai@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'somchai@example.test')
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'somchai@example.test')
            ->assertJsonPath('data.workspaces', []);

        // Logout revokes only the presented token.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reg_t02_duplicate_email_is_rejected_with_422(): void
    {
        $payload = [
            'name' => 'Somchai Jaidee',
            'email' => 'duplicate@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ];

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'validation');
    }
}
