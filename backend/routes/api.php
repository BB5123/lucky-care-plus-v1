<?php

use App\Http\Controllers\WorkspacePingController;
use App\Modules\Accounts\Http\Controllers\AuthController;
use App\Modules\Accounts\Http\Controllers\OtpController;
use App\Modules\Accounts\Http\Controllers\PinController;
use App\Modules\CareCrm\Http\Controllers\AppointmentController;
use App\Modules\CareCrm\Http\Controllers\CareTaskActionController;
use App\Modules\CareCrm\Http\Controllers\CareTaskController;
use App\Modules\CareCrm\Http\Controllers\TaskEvidenceController;
use App\Modules\Dashboard\Http\Controllers\DashboardController;
use App\Modules\HealthRecord\Http\Controllers\AllergyController;
use App\Modules\HealthRecord\Http\Controllers\ConditionController;
use App\Modules\HealthRecord\Http\Controllers\MeasurementController;
use App\Modules\HealthRecord\Http\Controllers\MedicationAdherenceController;
use App\Modules\HealthRecord\Http\Controllers\MedicationPlanController;
use App\Modules\HealthRecord\Http\Controllers\MedicationRecordController;
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

    // -- Health Vault (Phase 1) ---------------------------------------------
    // Every route declares its frozen permission ID (AP-001); the Authorizer
    // resolves the profile from the route and enforces scope + grant there.
    Route::prefix('/workspaces/{workspace}/profiles/{profile}')
        ->middleware(['auth:sanctum', 'throttle:60,1,health-vault', 'workspace.context'])
        ->group(function (): void {
            Route::get('/measurements/trend', [MeasurementController::class, 'trend'])
                ->middleware('authorize.permission:health_measurement.read');
            Route::get('/measurements', [MeasurementController::class, 'index'])
                ->middleware('authorize.permission:health_measurement.read');
            Route::post('/measurements', [MeasurementController::class, 'store'])
                ->middleware('authorize.permission:health_measurement.create');
            Route::patch('/measurements/{measurement}', [MeasurementController::class, 'update'])
                ->middleware('authorize.permission:health_measurement.correct');
            Route::delete('/measurements/{measurement}', [MeasurementController::class, 'destroy'])
                ->middleware('authorize.permission:health_measurement.delete');

            Route::get('/conditions', [ConditionController::class, 'index'])
                ->middleware('authorize.permission:health_condition.read');
            Route::post('/conditions', [ConditionController::class, 'store'])
                ->middleware('authorize.permission:health_condition.create');
            Route::patch('/conditions/{condition}', [ConditionController::class, 'update'])
                ->middleware('authorize.permission:health_condition.correct');

            Route::get('/allergies', [AllergyController::class, 'index'])
                ->middleware('authorize.permission:allergy.read');
            Route::post('/allergies', [AllergyController::class, 'store'])
                ->middleware('authorize.permission:allergy.update');
            Route::patch('/allergies/{allergy}', [AllergyController::class, 'update'])
                ->middleware('authorize.permission:allergy.update');

            // -- Medications -------------------------------------------------
            Route::get('/medication-adherence', [MedicationAdherenceController::class, 'index'])
                ->middleware('authorize.permission:medication_record.read');
            Route::get('/medication-plans', [MedicationPlanController::class, 'index'])
                ->middleware('authorize.permission:medication_plan.read');
            Route::post('/medication-plans', [MedicationPlanController::class, 'store'])
                ->middleware('authorize.permission:medication_plan.create');
            Route::patch('/medication-plans/{plan}', [MedicationPlanController::class, 'update'])
                ->middleware('authorize.permission:medication_plan.update');
            Route::post('/medication-plans/{plan}/discontinue', [MedicationPlanController::class, 'discontinue'])
                ->middleware('authorize.permission:medication_plan.discontinue');
            Route::get('/medication-records', [MedicationRecordController::class, 'index'])
                ->middleware('authorize.permission:medication_record.read');
            Route::post('/medication-records', [MedicationRecordController::class, 'store'])
                ->middleware('authorize.permission:medication_record.create');
            Route::patch('/medication-records/{record}', [MedicationRecordController::class, 'update'])
                ->middleware('authorize.permission:medication_record.correct');
        });

    // -- Care Tasks (Phase 1.3) ---------------------------------------------
    // Workspace-scoped by ADR-005. Every route declares its frozen permission
    // ID; the domain services enforce tier transitions, assignment and
    // separation of duties after the authorizer has proven the role gate.
    Route::prefix('/workspaces/{workspace}/care-tasks')
        ->middleware(['auth:sanctum', 'throttle:60,1,care-tasks', 'workspace.context'])
        ->group(function (): void {
            Route::get('/', [CareTaskController::class, 'index'])
                ->middleware('authorize.permission:care_task.read');
            Route::post('/', [CareTaskController::class, 'store'])
                ->middleware('authorize.permission:care_task.create');
            Route::get('/{task}', [CareTaskController::class, 'show'])
                ->middleware('authorize.permission:care_task.read');
            Route::get('/{task}/events', [CareTaskController::class, 'events'])
                ->middleware('authorize.permission:care_task.read');

            Route::post('/{task}/assign', [CareTaskActionController::class, 'assign'])
                ->middleware('authorize.permission:care_task.assign');
            Route::post('/{task}/accept', [CareTaskActionController::class, 'accept'])
                ->middleware('authorize.permission:care_task.accept');
            Route::post('/{task}/decline', [CareTaskActionController::class, 'decline'])
                ->middleware('authorize.permission:care_task.accept');
            Route::post('/{task}/start', [CareTaskActionController::class, 'start'])
                ->middleware('authorize.permission:care_task.start');
            Route::post('/{task}/complete', [CareTaskActionController::class, 'complete'])
                ->middleware('authorize.permission:care_task.complete');
            Route::post('/{task}/verify', [CareTaskActionController::class, 'verify'])
                ->middleware('authorize.permission:care_task.verify');
            Route::post('/{task}/reject', [CareTaskActionController::class, 'reject'])
                ->middleware('authorize.permission:care_task.verify');
            Route::post('/{task}/reopen', [CareTaskActionController::class, 'reopen'])
                ->middleware('authorize.permission:care_task.reopen');
            Route::post('/{task}/cancel', [CareTaskActionController::class, 'cancel'])
                ->middleware('authorize.permission:care_task.cancel');
            Route::post('/{task}/block', [CareTaskActionController::class, 'block'])
                ->middleware('authorize.permission:care_task.block');

            Route::post('/{task}/evidence', [TaskEvidenceController::class, 'store'])
                ->middleware('authorize.permission:care_task.evidence.create');
            Route::get('/{task}/evidence', [TaskEvidenceController::class, 'index'])
                ->middleware('authorize.permission:care_task.evidence.read');
        });

    // -- Appointments (Phase 1.4) -------------------------------------------
    // A profile-scoped coordination surface (APT-001). Every route declares
    // its frozen permission ID; the domain service owns the lifecycle and the
    // optimistic-locking version after the authorizer has proven the grant.
    Route::prefix('/workspaces/{workspace}/profiles/{profile}/appointments')
        ->middleware(['auth:sanctum', 'throttle:60,1,appointments', 'workspace.context'])
        ->group(function (): void {
            Route::get('/', [AppointmentController::class, 'index'])
                ->middleware('authorize.permission:appointment.read');
            Route::post('/', [AppointmentController::class, 'store'])
                ->middleware('authorize.permission:appointment.create');
            Route::get('/{appointment}', [AppointmentController::class, 'show'])
                ->middleware('authorize.permission:appointment.read');
            Route::patch('/{appointment}', [AppointmentController::class, 'update'])
                ->middleware('authorize.permission:appointment.update');
            Route::post('/{appointment}/cancel', [AppointmentController::class, 'cancel'])
                ->middleware('authorize.permission:appointment.cancel');
            Route::post('/{appointment}/attended', [AppointmentController::class, 'attended'])
                ->middleware('authorize.permission:appointment.update');
            Route::patch('/{appointment}/documents', [AppointmentController::class, 'documents'])
                ->middleware('authorize.permission:appointment.update');
        });

    // -- Dashboard (Phase 1.5, ADR-010) -------------------------------------
    // No `authorize.permission` here on purpose: the dashboard aggregates five
    // cards over several permissions and must prove each card separately
    // (ADR-010, CRM-023). The controller delegates to the Authorizer per
    // `(card, profile)`; `workspace.context` already returns 404 to
    // non-members before the controller runs.
    Route::get('/workspaces/{workspace}/dashboard', DashboardController::class)
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,dashboard',
            'workspace.context',
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
