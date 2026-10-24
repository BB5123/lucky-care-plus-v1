<?php

use App\Http\Controllers\WorkspacePingController;
use App\Modules\Accounts\Http\Controllers\AuthController;
use App\Modules\Accounts\Http\Controllers\OtpController;
use App\Modules\Accounts\Http\Controllers\PinController;
use App\Modules\AiGateway\Http\Controllers\AiExtractionController;
use App\Modules\AiGateway\Http\Controllers\AiSummaryController;
use App\Modules\AiGateway\Knowledge\Http\Controllers\KnowledgeAnswerController;
use App\Modules\CareCrm\Http\Controllers\AppointmentController;
use App\Modules\CareCrm\Http\Controllers\CareTaskActionController;
use App\Modules\CareCrm\Http\Controllers\CareTaskController;
use App\Modules\CareCrm\Http\Controllers\CareTaskTemplateController;
use App\Modules\CareCrm\Http\Controllers\EscalationPolicyController;
use App\Modules\CareCrm\Http\Controllers\TaskEvidenceController;
use App\Modules\Dashboard\Http\Controllers\DashboardController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\DocumentDownloadController;
use App\Modules\HealthRecord\Http\Controllers\AllergyController;
use App\Modules\HealthRecord\Http\Controllers\BriefController;
use App\Modules\HealthRecord\Http\Controllers\ConditionController;
use App\Modules\HealthRecord\Http\Controllers\DataExportController;
use App\Modules\HealthRecord\Http\Controllers\HealthReportController;
use App\Modules\HealthRecord\Http\Controllers\MeasurementController;
use App\Modules\HealthRecord\Http\Controllers\MedicationAdherenceController;
use App\Modules\HealthRecord\Http\Controllers\MedicationPlanController;
use App\Modules\HealthRecord\Http\Controllers\MedicationRecordController;
use App\Modules\HealthRecord\Http\Controllers\ProfileController;
use App\Modules\HealthRecord\Http\Controllers\ReportShareController;
use App\Modules\Notifications\Http\Controllers\NotificationDeliveryController;
use App\Modules\Notifications\Http\Controllers\NotificationPreferenceController;
use App\Modules\Timeline\Http\Controllers\TimelineController;
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

    // -- Public Doctor Brief (Phase 2.3, DB-003) ----------------------------
    // No auth middleware on purpose: the clinic has no account and the random
    // 256-bit token is the whole capability. Only the token is in the path; the
    // clinical payload is the response body (DB-004).
    Route::get('/briefs/{token}', BriefController::class)
        ->middleware('throttle:60,1,doctor-brief');

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

    // -- Self-service notifications (P1.6, ADR-005) -------------------------
    // No `{workspace}`: preferences and deliveries are user-owned. Each route
    // declares its frozen permission ID; the Authorizer receives a
    // `self_membership` resource and the query layer filters by the actor.
    Route::prefix('/me')
        ->middleware(['auth:sanctum', 'throttle:30,1,notification-self', 'self.context'])
        ->group(function (): void {
            Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show'])
                ->middleware('authorize.permission:notification.preference.read');
            Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update'])
                ->middleware('authorize.permission:notification.preference.update');
            Route::get('/notifications/deliveries', [NotificationDeliveryController::class, 'index'])
                ->middleware('authorize.permission:notification.delivery.read');
            Route::post('/notifications/{delivery}/read', [NotificationDeliveryController::class, 'markRead'])
                ->middleware('authorize.permission:notification.delivery.read');
        });

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

    // TASK INV-1: list and revoke pending invitations.
    //
    // Both routes declare `workspace.member.invite`, deliberately not
    // `workspace.member.read`. Revoking is the inverse of issuing, and the
    // pending-invitee list contains only addresses the inviter supplied, so
    // it is part of the invite authority. `.read` is the weaker "view the
    // member list" permission (Matrix A marks it D for Viewer/Caregiver) and
    // must not become a back door to invitee addresses. The baseline has no
    // dedicated revoke-invitation permission; inventing one is forbidden.
    //
    // The id is looked up inside the resolved workspace: another workspace's
    // invitation id is a neutral 404, never 403 (no enumeration).
    Route::get('/workspaces/{workspace}/invitations', [InvitationController::class, 'index'])
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,workspace-invitations-list',
            'workspace.context',
            'authorize.permission:workspace.member.invite',
        ]);

    Route::delete('/workspaces/{workspace}/invitations/{invitation}', [InvitationController::class, 'destroy'])
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,workspace-invitations-revoke',
            'workspace.context',
            'authorize.permission:workspace.member.invite',
        ]);

    // -- Profile picker (foundation for the health/appointment screens) ------
    // A profile id is required by every health endpoint, but the only list the
    // client had was the dashboard family card, gated by `person.read`. This
    // route lets an actor who holds a `profile.basic` grant enter the profile
    // they are entitled to without reading the family card. The route guard
    // proves membership + the role gate; `ProfileController` delegates the
    // per-profile decision to `Authorizer::authorizeBatch` (never a role
    // comparison in the controller). An empty list is a legitimate 200.
    Route::get('/workspaces/{workspace}/profiles', [ProfileController::class, 'index'])
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,profiles',
            'workspace.context',
            'authorize.permission:health_profile.read',
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

    // -- Medical documents (ADR-014) ---------------------------------------
    // Read, upload, export and delete are separate frozen permissions (F-06).
    // The URL endpoint issues a short-lived signed URL; the signed route below
    // re-runs the Authorizer at delivery time so a revoked grant cannot be
    // bypassed with an old link (ABD-014 / F-08). `medical_document.export`
    // (not `.read`) gates URL issuance and delivery because the baseline says
    // "Read an authorized document" is not "Export/download a document".
    Route::prefix('/workspaces/{workspace}/profiles/{profile}/documents')
        ->middleware(['auth:sanctum', 'throttle:60,1,documents', 'workspace.context'])
        ->group(function (): void {
            Route::get('/', [DocumentController::class, 'index'])
                ->middleware('authorize.permission:medical_document.read');
            Route::post('/', [DocumentController::class, 'store'])
                ->middleware('authorize.permission:medical_document.upload');
            Route::get('/{document}', [DocumentController::class, 'show'])
                ->middleware('authorize.permission:medical_document.read');
            Route::post('/{document}/url', [DocumentController::class, 'url'])
                ->middleware('authorize.permission:medical_document.export');
            Route::delete('/{document}', [DocumentController::class, 'destroy'])
                ->middleware('authorize.permission:medical_document.delete');
        });

    // Signed delivery. The signature binds the document id. The route still
    // requires authentication and runs the normal Authorizer pipeline for the
    // document's profile.
    Route::get('/documents/{document}/download', DocumentDownloadController::class)
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,document-download',
            'signed:relative',
            'documents.download.context',
            'authorize.permission:medical_document.export',
        ])
        ->name('documents.download');

    // -- PDPA data exports (permission catalogue `data_export.*`) ------------
    // Request, inspect and link are three separate actions with three frozen
    // permissions. The request and status endpoints use `data_export.request`;
    // only the link endpoint uses `data_export.download`, because issuing a
    // short-lived capability is the actual export (F-06). Both permissions are
    // mapped to the `data_export` consent purpose in the Authorizer, so a
    // withdrawn consent closes all three routes.
    //
    // The link points at a short-lived signed object-store URL. The object is
    // private and opaque (`exports/{tenant}/{export}.json`); the API never
    // returns the key and never mails the file.
    Route::prefix('/workspaces/{workspace}/profiles/{profile}/exports')
        ->middleware(['auth:sanctum', 'throttle:60,1,exports', 'workspace.context'])
        ->group(function (): void {
            Route::post('/', [DataExportController::class, 'store'])
                ->middleware('authorize.permission:data_export.request');
            Route::get('/{export}', [DataExportController::class, 'show'])
                ->middleware('authorize.permission:data_export.request');
            Route::post('/{export}/link', [DataExportController::class, 'link'])
                ->middleware('authorize.permission:data_export.download');
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

    // -- AI summaries (Phase 3, TASK P-1) -----------------------------------
    // The route declares the frozen permission `ai.report.create`. The
    // gateway then re-checks `ai.health_context.use` plus `ai_processing`
    // consent (step 1) before any section is fetched. `ai_disabled` is a 503
    // from the controller, not a route-level bypass.
    Route::post('/workspaces/{workspace}/profiles/{profile}/ai/summaries', AiSummaryController::class)
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,ai-summaries',
            'workspace.context',
            'authorize.permission:ai.report.create',
        ]);

    // -- Vision extraction (Phase 3, TASK P-3) ------------------------------
    // `ai.health_context.use` is the frozen permission for sending health
    // context to a model, and it is consent-gated on `ai_processing`. The
    // gateway re-checks both before reading a byte, then returns a draft only
    // (AIG-007: no AI write to a health table).
    Route::post('/workspaces/{workspace}/profiles/{profile}/ai/extractions', AiExtractionController::class)
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,ai-extractions',
            'workspace.context',
            'authorize.permission:ai.health_context.use',
        ]);

    // -- Health knowledge answers (TASK G4-KB) ------------------------------
    // Public health information, not patient data: the corpus is a read-only
    // SQLite store and no health row is read. The route still reuses the
    // frozen `ai.report.create` permission and the `ai_processing` consent
    // gate, and the gateway refuses diagnosis/prescribing questions before any
    // provider call.
    Route::post('/workspaces/{workspace}/profiles/{profile}/ai/knowledge-answers', KnowledgeAnswerController::class)
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,ai-knowledge',
            'workspace.context',
            'authorize.permission:ai.report.create',
        ]);

    // -- Doctor Brief snapshots (Phase 2.3) ---------------------------------
    // Read, create and export are distinct frozen permissions (F-06). The
    // section composition is narrower still: each section is separately
    // authorized inside DoctorBriefBuilder.
    Route::prefix('/workspaces/{workspace}/profiles/{profile}/health-reports')
        ->middleware(['auth:sanctum', 'throttle:60,1,health-reports', 'workspace.context'])
        ->group(function (): void {
            Route::get('/', [HealthReportController::class, 'index'])
                ->middleware('authorize.permission:health_report.read');
            Route::post('/', [HealthReportController::class, 'store'])
                ->middleware('authorize.permission:health_report.create');
            Route::get('/{report}', [HealthReportController::class, 'show'])
                ->middleware('authorize.permission:health_report.read');
            Route::post('/{report}/share', [ReportShareController::class, 'store'])
                ->middleware('authorize.permission:health_report.export');
            Route::delete('/{report}/shares/{share}', [ReportShareController::class, 'destroy'])
                ->middleware('authorize.permission:health_report.export');
        });

    // -- Recurring care-task templates (Phase 2.1) --------------------------
    // CR-003 / baseline v1.2.0: the schedule is its own resource. Reading a
    // routine does not consume `care_task.read`, and defining or deactivating
    // one does not consume `care_task.create` (ABD-017). Exceptions mutate an
    // existing schedule, so they use the update permission.
    Route::prefix('/workspaces/{workspace}/profiles/{profile}/task-templates')
        ->middleware(['auth:sanctum', 'throttle:60,1,task-templates', 'workspace.context'])
        ->group(function (): void {
            Route::get('/', [CareTaskTemplateController::class, 'index'])
                ->middleware('authorize.permission:care_task.template.read');
            Route::post('/', [CareTaskTemplateController::class, 'store'])
                ->middleware('authorize.permission:care_task.template.create');
            Route::get('/{template}', [CareTaskTemplateController::class, 'show'])
                ->middleware('authorize.permission:care_task.template.read');
            Route::patch('/{template}', [CareTaskTemplateController::class, 'update'])
                ->middleware('authorize.permission:care_task.template.update');
            Route::post('/{template}/deactivate', [CareTaskTemplateController::class, 'deactivate'])
                ->middleware('authorize.permission:care_task.template.deactivate');
            Route::post('/{template}/exceptions', [CareTaskTemplateController::class, 'addException'])
                ->middleware('authorize.permission:care_task.template.update');
        });

    // -- Escalation ladder (Phase 2.2) --------------------------------------
    // CR-003 / baseline v1.2.0: escalation policy is its own authority.
    // Reading the ladder is `escalation_policy.read`; replacing it or
    // materialising the defaults is `escalation_policy.update`. Running the
    // ladder is a scheduled system job with no actor and no permission check.
    Route::prefix('/workspaces/{workspace}/escalation-policies')
        ->middleware(['auth:sanctum', 'throttle:60,1,escalation-policies', 'workspace.context'])
        ->group(function (): void {
            Route::get('/', [EscalationPolicyController::class, 'index'])
                ->middleware('authorize.permission:escalation_policy.read');
            Route::put('/', [EscalationPolicyController::class, 'replace'])
                ->middleware('authorize.permission:escalation_policy.update');
            Route::post('/defaults', [EscalationPolicyController::class, 'defaults'])
                ->middleware('authorize.permission:escalation_policy.update');
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

    // -- Timeline (Phase 1.7) ------------------------------------------------
    // `timeline.read` only opens the surface. The controller re-checks the
    // owning scope of every entry and redacts anything the actor may not read
    // (F-06, CRM-013/014).
    Route::get('/workspaces/{workspace}/profiles/{profile}/timeline', TimelineController::class)
        ->middleware([
            'auth:sanctum',
            'throttle:60,1,timeline',
            'workspace.context',
            'authorize.permission:timeline.read',
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
