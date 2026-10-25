<?php

// ForceHttps and ReadyRateLimit live under bootstrap/ because this slice is
// only allowed to change bootstrap/config/tests/docs. Composer does not
// autoload bootstrap/, so require them explicitly before the app is built.

use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\EnsureAuthorized;
use App\Http\Middleware\EnsurePlatformAuthorized;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\ReadyRateLimit;
use App\Http\Middleware\ResolveSelfServiceContext;
use App\Http\Middleware\ResolveWorkspaceContext;
use App\Http\Responses\ProblemDetails;
use App\Modules\Documents\Http\Middleware\ResolveDocumentDownloadContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Readiness is distinct from liveness (`/up`); the check itself lives in
            // App\Http\Controllers\ReadinessController so it can be unit-tested and
            // reviewed like any other endpoint.
            Route::get('/ready', ReadinessController::class)
                ->middleware(ReadyRateLimit::class)
                ->name('ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Host-header validation is cheap here, but the internal probes use
        // `localhost`/`127.0.0.1` and Caddy's active health check uses the
        // `web` service name, so those are explicitly trusted alongside the
        // public host from APP_URL.
        $middleware->trustHosts(at: function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            $patterns = ['^localhost$', '^127\.0\.0\.1$', '^web$'];

            if (is_string($host) && $host !== '') {
                $patterns[] = '^'.preg_quote($host, '/').'$';
            }

            return $patterns;
        });

        // Always registered; the FORCE_HTTPS switch is read per request.
        $middleware->append(ForceHttps::class);

        $middleware->alias([
            'workspace.context' => ResolveWorkspaceContext::class,
            'self.context' => ResolveSelfServiceContext::class,
            'documents.download.context' => ResolveDocumentDownloadContext::class,
            'authorize.permission' => EnsureAuthorized::class,
            'authorize.platform' => EnsurePlatformAuthorized::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API error uses the RFC 9457 envelope from the contract, not
        // Laravel's default `{"message": ...}` shape (`.ai/07-API-CONTRACT.md §2`).
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ProblemDetails::make(
                $request,
                422,
                'validation',
                'Validation failed',
                'ข้อมูลที่ส่งมาไม่ถูกต้อง',
                errors: $e->errors(),
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ProblemDetails::make(
                $request,
                401,
                'unauthenticated',
                'Authentication required',
                'กรุณายืนยันตัวตนก่อนเข้าถึงข้อมูล',
            );
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ProblemDetails::make(
                $request,
                429,
                'rate_limited',
                'Too many requests',
                'มีการร้องขอบ่อยเกินไป กรุณาลองใหม่ภายหลัง',
                $e->getHeaders(),
            );
        });

        // A signed document link that was tampered with, expired, or signed for
        // a different URL is a capability failure, not a server error. Keep the
        // problem envelope so clients can distinguish it from a permission
        // denial by the Authorizer (ABD-014).
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ProblemDetails::make(
                $request,
                403,
                'invalid_signature',
                'Invalid signature',
                'ลิงก์นี้ไม่ถูกต้องหรือหมดอายุแล้ว',
            );
        });
    })->create();

// `withMiddleware` runs when the kernel is resolved, before LoadConfiguration.
// The proxy allow-list therefore has to be applied in a booting callback, which
// runs after the config repository is available.
$app->booting(function (Application $app): void {
    $trustedProxies = $app['config']->get('app.trusted_proxies');

    if (is_string($trustedProxies)) {
        $trustedProxies = trim($trustedProxies);
    }

    if ($trustedProxies !== null && $trustedProxies !== '' && $trustedProxies !== []) {
        TrustProxies::at($trustedProxies);
    }
});

return $app;
