<?php

use App\Http\Controllers\WorkspacePingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function (): void {
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
