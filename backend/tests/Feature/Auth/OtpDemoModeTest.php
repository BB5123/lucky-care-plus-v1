<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Accounts\Contracts\OtpSender;
use App\Modules\Accounts\Services\OtpDemoMode;
use App\Modules\Workspace\Models\Tenant;
use App\Support\Database\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\FakeOtpSender;
use Tests\TestCase;

/**
 * DEV-OTP — the development demo mode that echoes the code for device testing.
 *
 * The reference projects in `references/source/` (mohpromt-clone, paotang) do
 * the same thing for their Flutter clients, but this implementation adds two
 * guards they do not have: it is off by default and it refuses to be enabled in
 * production instead of quietly leaking codes.
 */
class OtpDemoModeTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0899999999';

    private User $user;

    private FakeOtpSender $sender;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // AuthAudit writes into the tenant-scoped security log, so a default
        // tenant must exist for the demo issuance to be audited at all.
        $this->tenant = Tenant::query()->create([
            'name' => 'Default',
            'slug' => 'default',
        ]);

        $this->user = User::factory()->create();
        $this->sender = new FakeOtpSender;

        $this->app->instance(OtpSender::class, $this->sender);
    }

    public function test_otp_demo_is_off_by_default_and_never_returns_the_code(): void
    {
        config(['otp.demo_mode' => false]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])
            ->assertStatus(202)
            ->assertJsonMissingPath('data.demo_code')
            ->assertJsonMissingPath('data.demo_mode');

        // The flow still works through the real sender.
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => (string) $this->sender->lastCode,
        ])->assertOk();
    }

    public function test_otp_demo_returns_a_usable_code_when_enabled(): void
    {
        config(['otp.demo_mode' => true]);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE])
            ->assertStatus(202)
            ->assertJsonPath('data.demo_mode', true);

        $code = (string) $response->json('data.demo_code');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        // The code returned is the same one the sender received, and the database
        // still holds only the hash.
        $this->assertSame((string) $this->sender->lastCode, $code);
        $this->assertNotSame($code, DB::table('otp_codes')->value('code_hash'));

        // It verifies like any other code — the demo shortcut is not a bypass.
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.phone', self::PHONE);

        // Demo issuance is audited, without the code. The audit table is
        // tenant-scoped, so read it inside the tenant context.
        TenantContext::run($this->tenant->id, '', function () use ($code): void {
            $this->assertDatabaseHas('security_audit_logs', [
                'action' => 'auth.otp.demo_issued',
                'result' => 'allow',
            ]);

            $metadata = (string) DB::table('security_audit_logs')
                ->where('action', 'auth.otp.demo_issued')
                ->value('metadata');

            $this->assertStringNotContainsString($code, $metadata);
        });
    }

    public function test_otp_demo_refuses_to_run_in_production(): void
    {
        config(['otp.demo_mode' => true]);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OTP_DEMO_MODE must not be enabled in production');

        app(OtpDemoMode::class)->enabled();
    }

    public function test_otp_demo_is_silent_when_disabled_even_in_production(): void
    {
        config(['otp.demo_mode' => false]);
        $this->app['env'] = 'production';

        $this->assertFalse(app(OtpDemoMode::class)->enabled());
    }
}
