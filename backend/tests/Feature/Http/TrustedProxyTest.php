<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TRP-T01..T04 - the trusted-proxy allow-list is a security control.
 *
 * The HTTP layer is exercised for real; only the proxy configuration is set
 * per test because TRUSTED_PROXIES is normally frozen at boot by
 * bootstrap/app.php.
 */
class TrustedProxyTest extends TestCase
{
    private const PROXY_IP = '10.0.0.5';

    private const CLIENT_IP = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        TrustProxies::flushState();
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    /**
     * Register a route that reports what the application actually sees.
     */
    private function registerProbe(): void
    {
        Route::get('/proxy-probe', function (Request $request) {
            return response()->json([
                'secure' => $request->isSecure(),
                'ip' => $request->ip(),
                'url' => url('/proxy-probe'),
                'secure_url' => secure_url('/proxy-probe'),
            ]);
        });
    }

    public function test_trp_t01_a_trusted_proxy_can_assert_https(): void
    {
        TrustProxies::at([self::PROXY_IP]);
        $this->registerProbe();

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'api.example.test',
                'X-Forwarded-Port' => '443',
            ])
            ->getJson('/proxy-probe');

        $response->assertOk()
            ->assertJsonPath('secure', true);

        $this->assertStringStartsWith('https://', $response->json('url'));
        $this->assertStringStartsWith('https://', $response->json('secure_url'));
    }

    public function test_trp_t02_a_trusted_proxy_reports_the_forwarded_client_ip(): void
    {
        TrustProxies::at([self::PROXY_IP]);
        $this->registerProbe();

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders(['X-Forwarded-For' => self::CLIENT_IP])
            ->getJson('/proxy-probe');

        $response->assertOk()
            ->assertJsonPath('ip', self::CLIENT_IP);
    }

    public function test_trp_t03_an_empty_trusted_proxy_list_ignores_spoofed_headers(): void
    {
        TrustProxies::flushState();
        $this->registerProbe();

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders([
                'X-Forwarded-For' => self::CLIENT_IP,
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('/proxy-probe');

        $response->assertOk()
            ->assertJsonPath('ip', self::PROXY_IP)
            ->assertJsonPath('secure', false);
    }

    public function test_trp_t04_force_https_redirects_http_and_the_default_does_not(): void
    {
        config(['app.force_https' => true]);

        $redirect = $this->get('/');

        $redirect->assertStatus(301);
        $this->assertStringStartsWith('https://', (string) $redirect->headers->get('Location'));

        config(['app.force_https' => false]);

        $this->get('/')->assertOk();
    }
}
