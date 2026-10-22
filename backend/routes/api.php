<?php

use App\Http\Controllers\WorkspacePingController;
use App\Modules\Accounts\Http\Controllers\AuthController;
use App\Modules\Accounts\Http\Controllers\OtpController;
use App\Modules\Accounts\Http\Controllers\PinController;
use App\Modules\Workspace\Http\Controllers\InvitationController;
use App\Modules\Workspace\Http\Controllers\MembershipController;
use App\Modules\Workspace\Http\Controllers\WorkspaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function (): void {
    // -- Public auth surface ------------------------------------------------
    // Strict limiter on credential/PIN/OTP guessing; a per-endpoint prefix
    // keeps one endpoint's budget from starving another (Laravel otherwise
    // shares the rate-limit bucket across every `throttle` route per user).
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:6,1,auth-register');

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1,auth-login');

    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware(['auth:sanctum', 'throttle:30,1,invitation-accept']);

    // -- Authenticated self-service ----------------------------------------
    Route::middleware(['auth:sanctum', 'throttle:30,1,me'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    Route::post('/auth/otp/request', [OtpController::class, 'request'])
        ->middleware(['auth:sanctum', 'throttle:6,1,otp-request']);

    Route::post('/auth/otp/verify', [OtpController::class, 'verify'])
        ->middleware(['auth:sanctum', 'throttle:6,1,otp-verify']);

    Route::post('/auth/pin/setup', [PinController::class, 'setup'])
        ->middleware(['auth:sanctum', 'throttle:30,1,pin-setup']);

    Route::post('/auth/pin/verify', [PinController::class, 'verify'])
        ->middleware(['auth:sanctum', 'throttle:6,1,pin-verify']);

    // -- Workspaces ---------------------------------------------------------
    Route::post('/workspaces', [WorkspaceController::class, 'store'])
        ->middleware(['auth:sanctum', 'throttle:30,1,workspace-create']);

    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])
        ->middleware([
            'auth:sanctum',
            'throttle:30,1,workspace-read',
            'workspace.context',
            'authorize.permission:workspace.read',
        ]);

    Route::get('/workspaces/{workspace}/members', [MembershipController::class, 'index'])
        ->middleware([
            'auth:sanctum',
            'throttle:30,1,workspace-members',
            'workspace.context',
            'authorize.permission:workspace.member.read',
        ]);

    Route::post('/workspaces/{workspace}/invitations', [InvitationController::class, 'store'])
        ->middleware([
            'auth:sanctum',
            'throttle:30,1,workspace-invitations',
            'workspace.context',
            'authorize.permission:workspace.member.invite',
        ]);

    // Protection is declared on the route: authentication, server-side
    // workspace resolution, then the Authorizer pipeline. The endpoint itself
    // is intentionally trivial — it exists to exercise the middleware stack.
    Route::get('/workspaces/{workspace}/ping', WorkspacePingController::class)
        ->middleware([
            'auth:sanctum',
            'workspace.context',
            'authorize.permission:workspace.read',
        ]);
});
