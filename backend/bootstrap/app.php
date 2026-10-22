<?php

use App\Http\Middleware\EnsureAuthorized;
use App\Http\Middleware\ResolveWorkspaceContext;
use App\Http\Responses\ProblemDetails;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
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
    })->create();
