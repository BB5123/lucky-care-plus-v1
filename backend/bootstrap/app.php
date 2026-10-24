<?php

use App\Http\Middleware\EnsureAuthorized;
use App\Http\Middleware\ResolveSelfServiceContext;
use App\Http\Middleware\ResolveWorkspaceContext;
use App\Http\Responses\ProblemDetails;
use App\Modules\Documents\Http\Middleware\ResolveDocumentDownloadContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'workspace.context' => ResolveWorkspaceContext::class,
            'self.context' => ResolveSelfServiceContext::class,
            'documents.download.context' => ResolveDocumentDownloadContext::class,
            'authorize.permission' => EnsureAuthorized::class,
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
