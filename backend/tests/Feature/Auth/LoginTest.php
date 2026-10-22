<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Workspace\Models\Tenant;
use App\Support\Database\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOGIN-T01 — a valid password issues a token, a wrong one is a generic 422.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_t01_valid_credentials_return_a_token_and_wrong_password_is_generic(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.test',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertIsString($response->json('data.token'));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.test',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonMissing(['email' => 'login@example.test']);
    }

    public function test_login_failure_is_audited_without_phi(): void
    {
        Tenant::query()->forceCreate([
            'name' => 'Audit Tenant',
            'slug' => 'default',
        ]);

        $user = User::factory()->create([
            'email' => 'audited@example.test',
            'password' => 'correct-horse-battery',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'audited@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        TenantContext::set(Tenant::query()->value('id'), '');

        $row = DB::table('security_audit_logs')
            ->where('action', 'auth.login')
            ->where('result', 'deny')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($user->id, (int) $row->actor_id);
        $this->assertStringNotContainsString('audited@example.test', (string) $row->metadata);

        TenantContext::clear();
    }
}
