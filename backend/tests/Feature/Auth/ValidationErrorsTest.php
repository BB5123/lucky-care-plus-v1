<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AP-004 — a 422 must carry per-field messages so a client can bind the error to
 * the control the user has to fix, instead of showing one generic sentence.
 *
 * The frontend Health Vault forms rely on this shape (`errors{}`); without it
 * they can only render a form-level message.
 */
class ValidationErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_validation_carries_field_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonStructure([
                'type',
                'title',
                'status',
                'code',
                'detail',
                'trace_id',
                'errors' => ['email', 'password'],
            ]);

        $errors = $response->json('errors');

        $this->assertIsArray($errors['email']);
        $this->assertNotEmpty($errors['email'][0]);
    }

    public function test_a_valid_request_has_no_errors_key(): void
    {
        User::factory()->create([
            'email' => 'valid@example.com',
            'password' => bcrypt('correct-horse-battery'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'valid@example.com',
            'password' => 'correct-horse-battery',
        ])->assertOk()->assertJsonMissingPath('errors');
    }
}
