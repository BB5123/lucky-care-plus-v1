<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
