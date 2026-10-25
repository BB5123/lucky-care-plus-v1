<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AUTH-401 — a missing/invalid token is a problem+json 401, never a redirect.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_401_me_without_token_returns_problem_json(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 401)
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonStructure(['type', 'title', 'status', 'code', 'detail', 'trace_id']);
    }

    public function test_auth_401_invalid_bearer_token_returns_problem_json(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'unauthenticated');
    }

    /**
     * CR-005-UI: `/me` is the caller's own record, so the explicit platform
     * flag is exposed for the web app to hide the review screen. It is false
     * for a normal user and becomes true after `platform:grant`.
     */
    public function test_me_exposes_the_platform_admin_flag_after_platform_grant(): void
    {
        Tenant::query()->create([
            'name' => 'Default',
            'slug' => 'default',
        ]);

        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.is_platform_admin', false);

        $this->artisan('platform:grant', ['email' => $user->email])
            ->assertExitCode(0);

        // The CLI wrote another model instance; a real request re-resolves the
        // actored user, so the test does the same before the second call.
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.is_platform_admin', true);
    }
}
