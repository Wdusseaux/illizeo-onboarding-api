<?php

use App\Http\Controllers\Api\V1\ActionController;
use App\Http\Controllers\Api\V1\ActionTypeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollaborateurController;
use App\Http\Controllers\Api\V1\ContratController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EmailTemplateController;
use App\Http\Controllers\Api\V1\GroupeController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\DocuSignController;
use App\Http\Controllers\Api\V1\UgoSignController;
use App\Http\Controllers\Api\V1\SuccessFactorsController;
use App\Http\Controllers\Api\V1\PersonioController;
use App\Http\Controllers\Api\V1\TeamsController;
use App\Http\Controllers\Api\V1\LuccaController;
use App\Http\Controllers\Api\V1\TeamtailorController;
use App\Http\Controllers\Api\V1\BambooHRController;
use App\Http\Controllers\Api\V1\WorkdayController;
use App\Http\Controllers\Api\V1\OcrController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\AiChatController;
use App\Http\Controllers\Api\V1\StripeController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\CollaborateurActionController;
use App\Http\Controllers\Api\V1\UserManagementController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\EntraIdController;
use App\Http\Controllers\Api\V1\OnboardingTeamController;
use App\Http\Controllers\Api\V1\FieldConfigController;
use App\Http\Controllers\Api\V1\CompanyBlockController;
use App\Http\Controllers\Api\V1\CompanySettingController;
use App\Http\Controllers\Api\V1\NotificationConfigController;
use App\Http\Controllers\Api\V1\ParcoursCategorieController;
use App\Http\Controllers\Api\V1\ParcoursController;
use App\Http\Controllers\Api\V1\PhaseController;
use App\Http\Controllers\Api\V1\CooptationController;
use App\Http\Controllers\Api\V1\DataExportController;
use App\Http\Controllers\Api\V1\NpsSurveyController;
use App\Http\Controllers\Api\V1\TenantRegistrationController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\BadgeController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SuperAdminController;
use App\Http\Controllers\Api\V1\EquipmentController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SignatureDocumentController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\BuddyPairController;
use App\Http\Controllers\Api\V1\ProjetController;
use App\Http\Controllers\Api\V1\TacheController;
use App\Http\Controllers\Api\V1\SousProjetController;
use App\Http\Controllers\Api\V1\SousTacheController;
use App\Http\Controllers\Api\V1\CommentaireTacheController;
use App\Http\Controllers\Api\V1\JalonController;
use App\Http\Controllers\Api\V1\LigneCoutController;
use App\Http\Controllers\Api\V1\TauxHoraireController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\ApiLogController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

// ─── Public (no tenant, no auth) ────────────────────────────
Route::get('/ping', fn () => response()->json(['status' => 'ok', 'app' => 'Illizeo API', 'version' => 'v1']));

// ─── Tenant registration (central, no tenant context) ───────
Route::post('/register-tenant', [TenantRegistrationController::class, 'register']);
Route::post('/check-tenant', [TenantRegistrationController::class, 'checkAvailability']);

// ─── Public pricing (central, no auth) ─────────────────────
Route::get('/plans', [PlanController::class, 'index']);
Route::get('/exchange-rates', function () {
    $cached = cache()->get('exchange_rates_chf');
    if ($cached) return response()->json($cached);
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(5)->get('https://api.exchangerate-api.com/v4/latest/CHF');
        if ($response->successful()) {
            $data = $response->json();
            cache()->put('exchange_rates_chf', $data, 3600); // Cache 1h
            return response()->json($data);
        }
    } catch (\Exception $e) {}
    return response()->json(['rates' => ['CHF' => 1, 'EUR' => 1.09, 'USD' => 1.28, 'GBP' => 0.95]]);
});
Route::post('/plans/subscribe', [PlanController::class, 'subscribe']);

// ─── Stripe Webhook (no auth, no tenant) ───────────────────
Route::post('/stripe/webhook', [\App\Http\Controllers\Api\V1\StripeWebhookController::class, 'handle']);

// Super admin routes are inside the tenant-scoped group below (need tenant context for Sanctum token resolution)

// ─── Tenant-scoped ──────────────────────────────────────────
Route::middleware([InitializeTenancyByRequestData::class])->group(function () {

    // Tenant check (public, verifies tenant exists)
    Route::get('/tenant-check', fn () => response()->json(['status' => 'ok', 'tenant' => tenant('id')]));

    // Auth (public within tenant)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordController::class, 'resetPassword']);

    // Password policy (public — needed before auth for register/reset forms)
    Route::get('/password-policy', [PasswordController::class, 'getPolicy']);

    // Tenant branding (public — needed before auth for login page gradient/logo)
    Route::get('/tenant-branding', function () {
        $keys = ['login_gradient_start', 'login_gradient_end', 'login_bg_image', 'custom_logo', 'custom_logo_full'];
        $settings = \App\Models\CompanySetting::whereIn('key', $keys)->pluck('value', 'key');
        return response()->json($settings);
    });

    // 2FA verify (no auth required — called after login when 2FA is enabled)
    Route::post('2fa/verify', [TwoFactorController::class, 'verify']);

    // Support access login (no auth — token-based)
    Route::post('support-login', [\App\Http\Controllers\Api\V1\SupportAccessController::class, 'loginWithToken']);

    // NPS public response (no auth needed — anonymous token-based)
    Route::get('nps/respond/{token}', [NpsSurveyController::class, 'getByToken']);
    Route::post('nps/respond/{token}', [NpsSurveyController::class, 'respond']);

    // Protected (auth required)
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Current user permissions
        Route::get('me/collaborateur', function (\Illuminate\Http\Request $request) {
            $collab = \App\Models\Collaborateur::with(['parcours.categorie', 'manager:id,prenom,nom', 'hrManager:id,prenom,nom'])
                ->where('user_id', $request->user()->id)
                ->orWhere('email', $request->user()->email)
                ->first();
            if (!$collab) return response()->json(null);
            $data = $collab->toArray();
            // Include phases and actions for the assigned parcours
            if ($collab->parcours_id) {
                $data['parcours_phases'] = \App\Models\Phase::whereHas('parcours', fn($q) => $q->where('parcours.id', $collab->parcours_id))
                    ->orderBy('ordre')->get();
                $actions = \App\Models\Action::where('parcours_id', $collab->parcours_id)
                    ->with(['actionType', 'phase'])
                    ->get();
                // Enrich actions with assignment data (assignment_id, status, completed_at)
                $assignments = \App\Models\CollaborateurAction::where('collaborateur_id', $collab->id)
                    ->get()
                    ->keyBy('action_id');
                $data['parcours_actions'] = $actions->map(function ($action) use ($assignments) {
                    $a = $action->toArray();
                    $a['phase_nom'] = $action->phase?->nom;
                    $a['type'] = $action->actionType?->slug ?? 'tache';
                    $assignment = $assignments->get($action->id);
                    $a['assignment_id'] = $assignment?->id;
                    $a['assignment_status'] = $assignment?->status ?? 'a_faire';
                    $a['completed_at'] = $assignment?->completed_at;
                    return $a;
                });
            }
            // Include team/accompagnants
            $accompagnants = \App\Models\CollaborateurAccompagnant::where('collaborateur_id', $collab->id)
                ->with('user')
                ->get()
                ->map(fn ($a) => ['user_id' => $a->user_id, 'name' => $a->user?->name, 'role' => $a->role]);
            $data['accompagnants'] = $accompagnants;

            return response()->json($data);
        });

        Route::get('me/permissions', function (\Illuminate\Http\Request $request) {
            return response()->json([
                'permissions' => $request->user()->getEffectivePermissions(),
                'roles' => $request->user()->customRoles()->where('actif', true)->pluck('slug'),
                'is_super_admin' => $request->user()->customRoles()->where('slug', 'super_admin')->exists() || $request->user()->hasRole('super_admin'),
            ]);
        });

        // ── Super Admin (operates on central DB, auth via tenant token) ──
        Route::prefix('super-admin')->group(function () {
            Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
            Route::get('tenants', [SuperAdminController::class, 'listTenants']);
            Route::get('tenants/{tenantId}', [SuperAdminController::class, 'showTenant']);
            Route::put('tenants/{tenantId}', [SuperAdminController::class, 'updateTenant']);
            Route::delete('tenants/{tenantId}', [SuperAdminController::class, 'deleteTenant']);
            Route::get('plans', [SuperAdminController::class, 'index']);
            Route::post('plans', [SuperAdminController::class, 'store']);
            Route::put('plans/{plan}', [SuperAdminController::class, 'update']);
            Route::delete('plans/{plan}', [SuperAdminController::class, 'destroy']);
            Route::get('plans/{plan}/modules', [SuperAdminController::class, 'listModules']);
            Route::put('plans/{plan}/modules', [SuperAdminController::class, 'updateModules']);
            Route::get('subscriptions', [SuperAdminController::class, 'listSubscriptions']);
            Route::get('invoices', [SuperAdminController::class, 'listInvoices']);
            Route::post('invoices/{invoiceId}/mark-paid', [SuperAdminController::class, 'markInvoicePaid']);
            Route::get('stripe-config', [SuperAdminController::class, 'getStripeConfig']);
            Route::put('stripe-config', [SuperAdminController::class, 'updateStripeConfig']);
            Route::get('ai-config', [SuperAdminController::class, 'getAiConfig']);
            Route::post('ai-config', [SuperAdminController::class, 'updateAiConfig']);
            Route::get('ai-usage', [SuperAdminController::class, 'getAiUsage']);
        });

        // 2FA management (auth required)
        Route::get('2fa/status', [TwoFactorController::class, 'status']);
        Route::post('2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('2fa/disable', [TwoFactorController::class, 'disable']);
        Route::post('2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);

        // ── Collaborateurs ──────────────────────────────────
        Route::get('collaborateurs', [CollaborateurController::class, 'index'])->middleware('permission:collaborateurs,view');
        Route::post('collaborateurs', [CollaborateurController::class, 'store'])->middleware('permission:collaborateurs,edit');
        Route::get('collaborateurs/{collaborateur}', [CollaborateurController::class, 'show'])->middleware('permission:collaborateurs,view');
        Route::put('collaborateurs/{collaborateur}', [CollaborateurController::class, 'update'])->middleware('permission:collaborateurs,edit');
        Route::patch('collaborateurs/{collaborateur}', [CollaborateurController::class, 'update'])->middleware('permission:collaborateurs,edit');
        Route::delete('collaborateurs/{collaborateur}', [CollaborateurController::class, 'destroy'])->middleware('permission:collaborateurs,edit');
        Route::post('collaborateurs/purge-demo', [CollaborateurController::class, 'purgeDemo'])->middleware('role:super_admin|admin|admin_rh');

        // ── Parcours ────────────────────────────────────────
        Route::get('parcours', [ParcoursController::class, 'index'])->middleware('permission:parcours,view');
        Route::post('parcours', [ParcoursController::class, 'store'])->middleware('permission:parcours,edit');
        Route::get('parcours/{parcour}', [ParcoursController::class, 'show'])->middleware('permission:parcours,view');
        Route::put('parcours/{parcour}', [ParcoursController::class, 'update'])->middleware('permission:parcours,edit');
        Route::patch('parcours/{parcour}', [ParcoursController::class, 'update'])->middleware('permission:parcours,edit');
        Route::delete('parcours/{parcour}', [ParcoursController::class, 'destroy'])->middleware('permission:parcours,edit');
        Route::post('parcours/{parcour}/duplicate', [ParcoursController::class, 'duplicate'])->middleware('permission:parcours,edit');

        Route::get('parcours-categories', [ParcoursCategorieController::class, 'index'])->middleware('permission:parcours,view');
        Route::get('parcours-categories/{parcoursCategorie}', [ParcoursCategorieController::class, 'show'])->middleware('permission:parcours,view');

        // ── Phases ──────────────────────────────────────────
        Route::get('phases', [PhaseController::class, 'index'])->middleware('permission:parcours,view');
        Route::post('phases', [PhaseController::class, 'store'])->middleware('permission:parcours,edit');
        Route::get('phases/{phase}', [PhaseController::class, 'show'])->middleware('permission:parcours,view');
        Route::put('phases/{phase}', [PhaseController::class, 'update'])->middleware('permission:parcours,edit');
        Route::patch('phases/{phase}', [PhaseController::class, 'update'])->middleware('permission:parcours,edit');
        Route::delete('phases/{phase}', [PhaseController::class, 'destroy'])->middleware('permission:parcours,edit');

        // ── Actions ─────────────────────────────────────────
        Route::get('actions', [ActionController::class, 'index'])->middleware('permission:parcours,view');
        Route::post('actions', [ActionController::class, 'store'])->middleware('permission:parcours,edit');
        Route::get('actions/{action}', [ActionController::class, 'show'])->middleware('permission:parcours,view');
        Route::put('actions/{action}', [ActionController::class, 'update'])->middleware('permission:parcours,edit');
        Route::patch('actions/{action}', [ActionController::class, 'update'])->middleware('permission:parcours,edit');
        Route::delete('actions/{action}', [ActionController::class, 'destroy'])->middleware('permission:parcours,edit');

        Route::get('action-types', [ActionTypeController::class, 'index'])->middleware('permission:parcours,view');
        Route::get('action-types/{actionType}', [ActionTypeController::class, 'show'])->middleware('permission:parcours,view');

        // ── Groupes ─────────────────────────────────────────
        Route::get('groupes', [GroupeController::class, 'index'])->middleware('permission:parcours,view');
        Route::post('groupes', [GroupeController::class, 'store'])->middleware('permission:parcours,edit');
        Route::get('groupes/{groupe}', [GroupeController::class, 'show'])->middleware('permission:parcours,view');
        Route::put('groupes/{groupe}', [GroupeController::class, 'update'])->middleware('permission:parcours,edit');
        Route::patch('groupes/{groupe}', [GroupeController::class, 'update'])->middleware('permission:parcours,edit');
        Route::delete('groupes/{groupe}', [GroupeController::class, 'destroy'])->middleware('permission:parcours,edit');

        // ── Documents ───────────────────────────────────────
        Route::get('documents', [DocumentController::class, 'index'])->middleware('permission:documents,view');
        Route::get('documents/summary', [DocumentController::class, 'summary'])->middleware('permission:documents,view');
        Route::post('documents', [DocumentController::class, 'store'])->middleware('permission:documents,edit');
        Route::post('documents/upload', [DocumentController::class, 'upload'])->middleware('permission:documents,edit');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->middleware('permission:documents,view');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->middleware('permission:documents,view');
        Route::put('documents/{document}', [DocumentController::class, 'update'])->middleware('permission:documents,edit');
        Route::patch('documents/{document}', [DocumentController::class, 'update'])->middleware('permission:documents,edit');
        Route::post('documents/{document}/validate', [DocumentController::class, 'validateDocument'])->middleware('permission:documents,edit');
        Route::post('documents/{document}/refuse', [DocumentController::class, 'refuse'])->middleware('permission:documents,edit');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->middleware('permission:documents,edit');
        Route::get('document-categories', [DocumentController::class, 'categories'])->middleware('permission:documents,view');
        Route::get('document-templates', [DocumentController::class, 'templates'])->middleware('permission:documents,view');
        Route::post('documents/{document}/upload-template', [DocumentController::class, 'uploadTemplate'])->middleware('permission:documents,edit');
        Route::get('documents/{document}/download-template', [DocumentController::class, 'downloadTemplate'])->middleware('permission:documents,view');

        // ── Contrats ────────────────────────────────────────
        Route::get('contrats', [ContratController::class, 'index'])->middleware('permission:contrats,view');
        Route::post('contrats', [ContratController::class, 'store'])->middleware('permission:contrats,edit');
        Route::get('contrats/{contrat}', [ContratController::class, 'show'])->middleware('permission:contrats,view');
        Route::put('contrats/{contrat}', [ContratController::class, 'update'])->middleware('permission:contrats,edit');
        Route::patch('contrats/{contrat}', [ContratController::class, 'update'])->middleware('permission:contrats,edit');
        Route::delete('contrats/{contrat}', [ContratController::class, 'destroy'])->middleware('permission:contrats,edit');
        Route::post('contrats/{contrat}/upload', [ContratController::class, 'uploadFile'])->middleware('permission:contrats,edit');
        Route::get('contrats/{contrat}/template', [ContratController::class, 'downloadTemplate'])->middleware('permission:contrats,view');
        Route::get('contrats/{contrat}/generate', [ContratController::class, 'generateForCollaborateur']);
        Route::get('contrats/{contrat}/download', [ContratController::class, 'downloadMerged']);

        // ── Workflows ───────────────────────────────────────
        Route::get('workflows', [WorkflowController::class, 'index'])->middleware('permission:workflows,view');
        Route::post('workflows', [WorkflowController::class, 'store'])->middleware('permission:workflows,edit');
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])->middleware('permission:workflows,view');
        Route::put('workflows/{workflow}', [WorkflowController::class, 'update'])->middleware('permission:workflows,edit');
        Route::patch('workflows/{workflow}', [WorkflowController::class, 'update'])->middleware('permission:workflows,edit');
        Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])->middleware('permission:workflows,edit');

        // ── Email Templates ─────────────────────────────────
        Route::get('email-templates', [EmailTemplateController::class, 'index'])->middleware('permission:workflows,view');
        Route::post('email-templates', [EmailTemplateController::class, 'store'])->middleware('permission:workflows,edit');
        Route::get('email-templates/{emailTemplate}', [EmailTemplateController::class, 'show'])->middleware('permission:workflows,view');
        Route::put('email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->middleware('permission:workflows,edit');
        Route::patch('email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->middleware('permission:workflows,edit');
        Route::delete('email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->middleware('permission:workflows,edit');
        Route::post('email-templates/{emailTemplate}/duplicate', [EmailTemplateController::class, 'duplicate'])->middleware('permission:workflows,edit');
        Route::post('email-templates/{emailTemplate}/send-test', [EmailTemplateController::class, 'sendTest'])->middleware('permission:workflows,edit');
        Route::get('mail-config', [EmailTemplateController::class, 'getMailConfig']);

        // ── Notifications Config ────────────────────────────
        Route::get('notifications-config', [NotificationConfigController::class, 'index'])->middleware('permission:workflows,view');
        Route::post('notifications-config', [NotificationConfigController::class, 'store'])->middleware('permission:workflows,edit');
        Route::put('notifications-config/{notificationConfig}', [NotificationConfigController::class, 'update'])->middleware('permission:workflows,edit');
        Route::patch('notifications-config/{notificationConfig}', [NotificationConfigController::class, 'update'])->middleware('permission:workflows,edit');
        Route::delete('notifications-config/{notificationConfig}', [NotificationConfigController::class, 'destroy'])->middleware('permission:workflows,edit');

        // ── Intégrations ────────────────────────────────
        Route::get('integrations', [IntegrationController::class, 'index'])->middleware('permission:integrations,view');
        Route::post('integrations', [IntegrationController::class, 'store'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}', [IntegrationController::class, 'show'])->middleware('permission:integrations,view');
        Route::put('integrations/{integration}', [IntegrationController::class, 'update'])->middleware('permission:integrations,admin');
        Route::patch('integrations/{integration}', [IntegrationController::class, 'update'])->middleware('permission:integrations,admin');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->middleware('permission:integrations,admin');

        // Onboarding teams
        Route::get('onboarding-teams', [OnboardingTeamController::class, 'index'])->middleware('permission:parcours,view');
        Route::post('onboarding-teams', [OnboardingTeamController::class, 'store'])->middleware('permission:parcours,edit');
        Route::put('onboarding-teams/{onboardingTeam}', [OnboardingTeamController::class, 'update'])->middleware('permission:parcours,edit');
        Route::delete('onboarding-teams/{onboardingTeam}', [OnboardingTeamController::class, 'destroy'])->middleware('permission:parcours,edit');
        Route::post('collaborateurs/{collaborateur}/assign-team', [OnboardingTeamController::class, 'assignTeam'])->middleware('permission:collaborateurs,edit');
        Route::post('collaborateurs/{collaborateur}/assign-accompagnant', [OnboardingTeamController::class, 'assignIndividual'])->middleware('permission:collaborateurs,edit');
        Route::get('collaborateurs/{collaborateur}/accompagnants', [OnboardingTeamController::class, 'collabAccompagnants'])->middleware('permission:collaborateurs,view');
        Route::delete('accompagnants/{collaborateurAccompagnant}', [OnboardingTeamController::class, 'removeAccompagnant'])->middleware('permission:collaborateurs,edit');
        Route::get('onboarding-teams/workload', [OnboardingTeamController::class, 'workload'])->middleware('permission:collaborateurs,view');

        // Entra ID
        Route::post('integrations/{integration}/entra/connect', [EntraIdController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/entra/disconnect', [EntraIdController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/entra/users', [EntraIdController::class, 'listADUsers'])->middleware('permission:integrations,view');
        Route::get('integrations/{integration}/entra/groups', [EntraIdController::class, 'listADGroups'])->middleware('permission:integrations,view');
        Route::get('integrations/{integration}/entra/groups/{groupId}/members', [EntraIdController::class, 'groupMembers'])->middleware('permission:integrations,view');
        // AD Group mappings
        Route::get('ad-group-mappings', [EntraIdController::class, 'listMappings'])->middleware('permission:integrations,view');
        Route::post('ad-group-mappings', [EntraIdController::class, 'createMapping'])->middleware('permission:integrations,admin');
        Route::put('ad-group-mappings/{adGroupMapping}', [EntraIdController::class, 'updateMapping'])->middleware('permission:integrations,admin');
        Route::delete('ad-group-mappings/{adGroupMapping}', [EntraIdController::class, 'deleteMapping'])->middleware('permission:integrations,admin');
        Route::post('ad-sync-users', [EntraIdController::class, 'syncUsers'])->middleware('permission:integrations,admin');

        // Field config
        Route::get('field-config', [FieldConfigController::class, 'index']);
        Route::post('field-config', [FieldConfigController::class, 'store'])->middleware('role:super_admin|admin|admin_rh');
        Route::put('field-config/{collaborateurFieldConfig}', [FieldConfigController::class, 'update'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('field-config/{collaborateurFieldConfig}', [FieldConfigController::class, 'destroy'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('field-config/bulk', [FieldConfigController::class, 'bulkUpdate'])->middleware('role:super_admin|admin|admin_rh');

        // Password
        Route::post('change-password', [PasswordController::class, 'changePassword']);
        Route::post('users/{user}/reset-password', [PasswordController::class, 'adminResetPassword'])->middleware('role:super_admin|admin|admin_rh');

        // User management (admin only)
        Route::get('users', [UserManagementController::class, 'index'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('users', [UserManagementController::class, 'store'])->middleware('role:super_admin|admin|admin_rh');
        Route::put('users/{user}', [UserManagementController::class, 'update'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('users/{user}', [UserManagementController::class, 'destroy'])->middleware('role:super_admin|admin');

        // Action assignments
        Route::post('assignments/assign', [CollaborateurActionController::class, 'assign'])->middleware('permission:parcours,edit');
        Route::get('assignments/collaborateur/{collaborateur}', [CollaborateurActionController::class, 'forCollaborateur'])->middleware('permission:collaborateurs,view');
        Route::get('assignments/action/{action}', [CollaborateurActionController::class, 'forAction'])->middleware('permission:parcours,view');
        Route::put('assignments/{collaborateurAction}', [CollaborateurActionController::class, 'updateStatus'])->middleware('permission:parcours,edit');
        Route::delete('assignments/{collaborateurAction}', [CollaborateurActionController::class, 'unassign'])->middleware('permission:parcours,edit');
        Route::get('my-actions', [CollaborateurActionController::class, 'myActions']);
        Route::get('my-nps-surveys', [NpsSurveyController::class, 'myPendingSurveys']);
        Route::post('my-actions/{collaborateurAction}/complete', [CollaborateurActionController::class, 'completeMyAction']);
        Route::post('my-actions/{collaborateurAction}/reactivate', [CollaborateurActionController::class, 'reactivateMyAction']);
        Route::post('my-actions/by-action/{action_id}/complete', function (\Illuminate\Http\Request $request, $actionId) {
            $collab = \App\Models\Collaborateur::where('user_id', $request->user()->id)->orWhere('email', $request->user()->email)->first();
            if (!$collab) return response()->json(['error' => 'No collaborateur'], 404);
            $a = \App\Models\CollaborateurAction::firstOrCreate(['collaborateur_id' => $collab->id, 'action_id' => $actionId], ['status' => 'a_faire']);
            $a->update(['status' => 'termine', 'completed_at' => now()]);
            return response()->json($a);
        });
        Route::post('my-actions/by-action/{action_id}/reactivate', function (\Illuminate\Http\Request $request, $actionId) {
            $collab = \App\Models\Collaborateur::where('user_id', $request->user()->id)->orWhere('email', $request->user()->email)->first();
            if (!$collab) return response()->json(['error' => 'No collaborateur'], 404);
            $a = \App\Models\CollaborateurAction::where('collaborateur_id', $collab->id)->where('action_id', $actionId)->first();
            if (!$a) return response()->json(['error' => 'Not found'], 404);
            $a->update(['status' => 'a_faire', 'completed_at' => null]);
            return response()->json($a);
        });

        // Company settings (appearance)
        Route::get('company-settings', [CompanySettingController::class, 'index']);
        Route::get('audit-logs', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'index'])->middleware('role:super_admin|admin|admin_rh');

        // ── Support Access ────────────────────────────────────
        Route::get('support-accesses', [\App\Http\Controllers\Api\V1\SupportAccessController::class, 'index'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('support-accesses', [\App\Http\Controllers\Api\V1\SupportAccessController::class, 'grant'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('support-accesses/{id}/revoke', [\App\Http\Controllers\Api\V1\SupportAccessController::class, 'revoke'])->middleware('role:super_admin|admin|admin_rh');

        // ── IP Whitelist ──────────────────────────────────────
        Route::get('ip-whitelist', [\App\Http\Controllers\Api\V1\IpWhitelistController::class, 'index'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('ip-whitelist', [\App\Http\Controllers\Api\V1\IpWhitelistController::class, 'store'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('ip-whitelist/toggle', [\App\Http\Controllers\Api\V1\IpWhitelistController::class, 'toggle'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('ip-whitelist/{id}', [\App\Http\Controllers\Api\V1\IpWhitelistController::class, 'destroy'])->middleware('role:super_admin|admin|admin_rh');

        // ── API Keys ──────────────────────────────────────────
        Route::get('api-keys', [ApiKeyController::class, 'index'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('api-keys/{id}/revoke', [ApiKeyController::class, 'revoke'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy'])->middleware('role:super_admin|admin|admin_rh');

        // ── Webhooks ─────────────────────────────────────────
        Route::get('webhooks-config', [WebhookController::class, 'index'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('webhooks-config', [WebhookController::class, 'store'])->middleware('role:super_admin|admin|admin_rh');
        Route::put('webhooks-config/{id}', [WebhookController::class, 'update'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('webhooks-config/{id}', [WebhookController::class, 'destroy'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('webhooks-config/{id}/test', [WebhookController::class, 'test'])->middleware('role:super_admin|admin|admin_rh');

        // ── API Logs ─────────────────────────────────────────
        Route::get('api-logs', [ApiLogController::class, 'index'])->middleware('role:super_admin|admin|admin_rh');

        // ── Security ──────────────────────────────────────────
        Route::get('security/sessions', [\App\Http\Controllers\Api\V1\SecurityController::class, 'listSessions']);
        Route::post('security/sessions/{id}/revoke', [\App\Http\Controllers\Api\V1\SecurityController::class, 'revokeSession']);
        Route::post('security/sessions/revoke-all', [\App\Http\Controllers\Api\V1\SecurityController::class, 'revokeAllOtherSessions']);
        Route::get('security/login-history', [\App\Http\Controllers\Api\V1\SecurityController::class, 'loginHistory']);
        Route::get('security/login-history/all', [\App\Http\Controllers\Api\V1\SecurityController::class, 'allLoginHistory'])->middleware('role:super_admin|admin|admin_rh');
        Route::get('security/settings', [\App\Http\Controllers\Api\V1\SecurityController::class, 'getSecuritySettings'])->middleware('role:super_admin|admin|admin_rh');
        Route::put('security/settings', [\App\Http\Controllers\Api\V1\SecurityController::class, 'updateSecuritySettings'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('security/schedules', [\App\Http\Controllers\Api\V1\SecurityController::class, 'storeSchedule'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('security/schedules/{id}', [\App\Http\Controllers\Api\V1\SecurityController::class, 'deleteSchedule'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('export/encrypted', [DataExportController::class, 'exportEncrypted'])->middleware('role:super_admin|admin|admin_rh');

        // ── Demo Mode ─────────────────────────────────────────
        Route::post('demo/seed', function () {
            \App\Models\CompanySetting::updateOrCreate(['key' => 'demo_mode'], ['value' => 'true']);
            // Run the demo seeder
            $seeder = new \Database\Seeders\IllizeoSeeder();
            $seeder->run();
            return response()->json(['message' => 'Données de démonstration créées', 'demo_mode' => true]);
        })->middleware('role:super_admin|admin|admin_rh');
        Route::put('company-settings', [CompanySettingController::class, 'update'])->middleware('role:super_admin|admin|admin_rh');

        // Company page blocks
        Route::get('company-blocks', [CompanyBlockController::class, 'activeBlocks']);
        Route::get('company-blocks/all', [CompanyBlockController::class, 'index'])->middleware('permission:company_page,view');
        Route::post('company-blocks', [CompanyBlockController::class, 'store'])->middleware('permission:company_page,edit');
        Route::put('company-blocks/{companyBlock}', [CompanyBlockController::class, 'update'])->middleware('permission:company_page,edit');
        Route::delete('company-blocks/{companyBlock}', [CompanyBlockController::class, 'destroy'])->middleware('permission:company_page,edit');
        Route::post('company-blocks/reorder', [CompanyBlockController::class, 'reorder'])->middleware('permission:company_page,edit');

        // Notifications
        Route::get('user-notifications', [NotificationController::class, 'index']);
        Route::get('user-notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('user-notifications/{userNotification}/read', [NotificationController::class, 'markRead']);
        Route::post('user-notifications/read-all', [NotificationController::class, 'markAllRead']);

        // Employee actions
        Route::post('employee/excited', [EmployeeController::class, 'markExcited']);

        // Messaging
        Route::get('messages/conversations', [MessageController::class, 'conversations']);
        Route::get('messages/conversations/{conversation}', [MessageController::class, 'messages']);
        Route::post('messages/send', [MessageController::class, 'send']);
        Route::get('messages/unread', [MessageController::class, 'unreadCount']);
        Route::get('messages/users', [MessageController::class, 'availableUsers']);

        // DocuSign
        Route::get('integrations/docusign/redirect', [DocuSignController::class, 'redirect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/docusign/disconnect', [DocuSignController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::post('docusign/envelopes', [DocuSignController::class, 'createEnvelope'])->middleware('permission:signatures,edit');
        Route::get('docusign/envelopes', [DocuSignController::class, 'listEnvelopes'])->middleware('permission:signatures,view');
        Route::get('docusign/envelopes/{envelopeId}', [DocuSignController::class, 'getEnvelope'])->middleware('permission:signatures,view');
        Route::post('docusign/envelopes/{envelopeId}/send', [DocuSignController::class, 'sendEnvelope'])->middleware('permission:signatures,edit');
        Route::post('docusign/envelopes/{envelopeId}/sender-view', [DocuSignController::class, 'senderView'])->middleware('permission:signatures,edit');
        Route::post('docusign/envelopes/{envelopeId}/void', [DocuSignController::class, 'voidEnvelope'])->middleware('permission:signatures,edit');

        // UgoSign API Key
        Route::post('integrations/{integration}/ugosign/connect', [UgoSignController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/ugosign/disconnect', [UgoSignController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/ugosign/envelopes', [UgoSignController::class, 'envelopes'])->middleware('permission:integrations,view');

        // SuccessFactors
        Route::post('integrations/{integration}/sap/connect', [SuccessFactorsController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/sap/disconnect', [SuccessFactorsController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/sap/employees', [SuccessFactorsController::class, 'employees'])->middleware('permission:integrations,view');
        Route::get('integrations/{integration}/sap/new-hires', [SuccessFactorsController::class, 'newHires'])->middleware('permission:integrations,view');
        Route::get('integrations/{integration}/sap/org-structure', [SuccessFactorsController::class, 'orgStructure'])->middleware('permission:integrations,view');

        // Teams
        Route::post('integrations/{integration}/teams/connect-webhook', [TeamsController::class, 'connectWebhook'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/teams/connect-graph', [TeamsController::class, 'connectGraph'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/teams/disconnect', [TeamsController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/teams/test', [TeamsController::class, 'testNotification'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/teams/meetings', [TeamsController::class, 'createMeeting'])->middleware('permission:parcours,edit');

        // Personio
        Route::post('integrations/{integration}/personio/connect', [PersonioController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/personio/disconnect', [PersonioController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/personio/employees', [PersonioController::class, 'employees'])->middleware('permission:integrations,view');

        // Lucca
        Route::post('integrations/{integration}/lucca/connect', [LuccaController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/lucca/disconnect', [LuccaController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/lucca/users', [LuccaController::class, 'users'])->middleware('permission:integrations,view');
        Route::get('integrations/{integration}/lucca/org-structure', [LuccaController::class, 'orgStructure'])->middleware('permission:integrations,view');

        // Teamtailor
        Route::post('integrations/{integration}/teamtailor/connect', [TeamtailorController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/teamtailor/disconnect', [TeamtailorController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/teamtailor/hired', [TeamtailorController::class, 'hiredCandidates'])->middleware('permission:integrations,view');

        // BambooHR
        Route::post('integrations/{integration}/bamboohr/connect', [BambooHRController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/bamboohr/disconnect', [BambooHRController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/bamboohr/employees', [BambooHRController::class, 'employees'])->middleware('permission:integrations,view');

        // Workday
        Route::post('integrations/{integration}/workday/connect', [WorkdayController::class, 'connect'])->middleware('permission:integrations,admin');
        Route::post('integrations/{integration}/workday/disconnect', [WorkdayController::class, 'disconnect'])->middleware('permission:integrations,admin');
        Route::get('integrations/{integration}/workday/workers', [WorkdayController::class, 'workers'])->middleware('permission:integrations,view');

        // ── Cooptation ─────────────────────────────────────
        // GET/POST cooptations accessible to all authenticated users (employees can view their own & create)
        Route::get('cooptations', [CooptationController::class, 'index']);
        Route::get('cooptations/{cooptation}', [CooptationController::class, 'show']);
        Route::post('cooptations', [CooptationController::class, 'store']);
        Route::put('cooptations/{cooptation}', [CooptationController::class, 'update'])->middleware('permission:cooptation,edit');
        Route::delete('cooptations/{cooptation}', [CooptationController::class, 'destroy'])->middleware('permission:cooptation,edit');
        Route::post('cooptations/{cooptation}/mark-hired', [CooptationController::class, 'markHired'])->middleware('permission:cooptation,edit');
        Route::post('cooptations/{cooptation}/validate', [CooptationController::class, 'validate'])->middleware('permission:cooptation,edit');
        Route::post('cooptations/{cooptation}/mark-rewarded', [CooptationController::class, 'markRewarded'])->middleware('permission:cooptation,edit');
        Route::post('cooptations/{cooptation}/refuse', [CooptationController::class, 'refuse'])->middleware('permission:cooptation,edit');
        Route::post('cooptations/{cooptation}/upload-cv', [CooptationController::class, 'uploadCV'])->middleware('permission:cooptation,edit');
        Route::get('cooptation-stats', [CooptationController::class, 'stats'])->middleware('permission:cooptation,view');
        Route::get('cooptation-settings', [CooptationController::class, 'getSettings'])->middleware('permission:cooptation,view');
        Route::put('cooptation-settings', [CooptationController::class, 'updateSettings'])->middleware('permission:cooptation,edit');

        // Cooptation campaigns (also accessible to employees — no permission gate on GET)
        Route::get('cooptation-campaigns', [CooptationController::class, 'listCampaigns']);
        Route::post('cooptation-campaigns', [CooptationController::class, 'createCampaign'])->middleware('permission:cooptation,edit');
        Route::put('cooptation-campaigns/{campaign}', [CooptationController::class, 'updateCampaign'])->middleware('permission:cooptation,edit');
        Route::delete('cooptation-campaigns/{campaign}', [CooptationController::class, 'deleteCampaign'])->middleware('permission:cooptation,edit');
        Route::get('cooptation-leaderboard', [CooptationController::class, 'leaderboard']);

        // ── Badges & Gamification ───────────────────────────
        Route::get('badges', [BadgeController::class, 'earned'])->middleware('permission:gamification,view');
        Route::get('badges/my', [BadgeController::class, 'myBadges'])->middleware('permission:gamification,view');
        Route::get('badges/user/{userId}', [BadgeController::class, 'userBadges'])->middleware('permission:gamification,view');
        Route::get('badge-templates', [BadgeController::class, 'templates'])->middleware('permission:gamification,view');
        Route::post('badge-templates', [BadgeController::class, 'storeTemplate'])->middleware('permission:gamification,edit');
        Route::put('badge-templates/{badgeTemplate}', [BadgeController::class, 'updateTemplate'])->middleware('permission:gamification,edit');
        Route::delete('badge-templates/{badgeTemplate}', [BadgeController::class, 'destroyTemplate'])->middleware('permission:gamification,edit');
        Route::post('badges/award', [BadgeController::class, 'award'])->middleware('permission:gamification,edit');
        Route::delete('badges/{badge}', [BadgeController::class, 'revoke'])->middleware('permission:gamification,edit');

        // ── NPS & Satisfaction ──────────────────────────────
        Route::get('nps-surveys', [NpsSurveyController::class, 'index'])->middleware('permission:nps,view');
        Route::post('nps-surveys', [NpsSurveyController::class, 'store'])->middleware('permission:nps,edit');
        Route::get('nps-surveys/{npsSurvey}', [NpsSurveyController::class, 'show'])->middleware('permission:nps,view');
        Route::put('nps-surveys/{npsSurvey}', [NpsSurveyController::class, 'update'])->middleware('permission:nps,edit');
        Route::patch('nps-surveys/{npsSurvey}', [NpsSurveyController::class, 'update'])->middleware('permission:nps,edit');
        Route::delete('nps-surveys/{npsSurvey}', [NpsSurveyController::class, 'destroy'])->middleware('permission:nps,edit');
        Route::get('nps-stats', [NpsSurveyController::class, 'stats'])->middleware('permission:nps,view');
        Route::post('nps-surveys/{npsSurvey}/send', [NpsSurveyController::class, 'sendToCollaborateur'])->middleware('permission:nps,edit');
        Route::post('nps-surveys/{npsSurvey}/send-all', [NpsSurveyController::class, 'sendToAll'])->middleware('permission:nps,edit');

        // ── Analytics ───────────────────────────────────────
        Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard'])->middleware('permission:reports,view');

        // ── Equipment Management ──────────────────────────
        Route::get('equipment-types', [EquipmentController::class, 'types'])->middleware('permission:equipements,view');
        Route::post('equipment-types', [EquipmentController::class, 'storeType'])->middleware('permission:equipements,edit');
        Route::put('equipment-types/{equipmentType}', [EquipmentController::class, 'updateType'])->middleware('permission:equipements,edit');
        Route::delete('equipment-types/{equipmentType}', [EquipmentController::class, 'destroyType'])->middleware('permission:equipements,edit');
        Route::get('equipments', [EquipmentController::class, 'index'])->middleware('permission:equipements,view');
        Route::get('equipments/stats', [EquipmentController::class, 'stats'])->middleware('permission:equipements,view');
        Route::post('equipments', [EquipmentController::class, 'store'])->middleware('permission:equipements,edit');
        Route::get('equipments/{equipment}', [EquipmentController::class, 'show'])->middleware('permission:equipements,view');
        Route::put('equipments/{equipment}', [EquipmentController::class, 'update'])->middleware('permission:equipements,edit');
        Route::delete('equipments/{equipment}', [EquipmentController::class, 'destroy'])->middleware('permission:equipements,edit');
        Route::post('equipments/{equipment}/assign', [EquipmentController::class, 'assign'])->middleware('permission:equipements,edit');
        Route::post('equipments/{equipment}/unassign', [EquipmentController::class, 'unassign'])->middleware('permission:equipements,edit');
        Route::get('equipment-packages', [EquipmentController::class, 'packages'])->middleware('permission:equipements,view');
        Route::post('equipment-packages', [EquipmentController::class, 'storePackage'])->middleware('permission:equipements,edit');
        Route::put('equipment-packages/{equipmentPackage}', [EquipmentController::class, 'updatePackage'])->middleware('permission:equipements,edit');
        Route::delete('equipment-packages/{equipmentPackage}', [EquipmentController::class, 'destroyPackage'])->middleware('permission:equipements,edit');
        Route::post('equipment-packages/{equipmentPackage}/provision', [EquipmentController::class, 'provisionPackage'])->middleware('permission:equipements,edit');

        // ── Signature Documents (lecture + signature) ─────
        Route::get('signature-documents', [SignatureDocumentController::class, 'index']);
        Route::post('signature-documents', [SignatureDocumentController::class, 'store'])->middleware('role:super_admin|admin|admin_rh');
        Route::put('signature-documents/{signatureDocument}', [SignatureDocumentController::class, 'update'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('signature-documents/{signatureDocument}', [SignatureDocumentController::class, 'destroy'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('signature-documents/{signatureDocument}/upload', [SignatureDocumentController::class, 'uploadFile'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('signature-documents/{signatureDocument}/send', [SignatureDocumentController::class, 'sendTo'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('signature-documents/{signatureDocument}/send-all', [SignatureDocumentController::class, 'sendToAll'])->middleware('role:super_admin|admin|admin_rh');
        Route::get('signature-documents/{signatureDocument}/acknowledgements', [SignatureDocumentController::class, 'acknowledgements']);
        Route::post('acknowledgements/{acknowledgement}/sign', [SignatureDocumentController::class, 'acknowledge']);
        Route::post('acknowledgements/{acknowledgement}/refuse', [SignatureDocumentController::class, 'refuse']);
        Route::get('my-pending-signatures', [SignatureDocumentController::class, 'myPending']);
        Route::get('signature-documents/{signatureDocument}/my-acknowledgement', [SignatureDocumentController::class, 'myAcknowledgement']);

        // ── Dossier Validation & SIRH Export ───────────────
        Route::get('collaborateurs/{collaborateur}/dossier-check', [\App\Http\Controllers\Api\V1\DossierController::class, 'check']);
        Route::post('collaborateurs/{collaborateur}/dossier-validate', [\App\Http\Controllers\Api\V1\DossierController::class, 'validate'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('collaborateurs/{collaborateur}/dossier-export', [\App\Http\Controllers\Api\V1\DossierController::class, 'export'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('collaborateurs/{collaborateur}/dossier-reset', [\App\Http\Controllers\Api\V1\DossierController::class, 'reset'])->middleware('role:super_admin|admin|admin_rh');

        // ── Data export & RGPD ─────────────────────────────
        Route::get('export/all', [DataExportController::class, 'exportAll'])->middleware('role:super_admin|admin|admin_rh');
        Route::get('export/collaborateurs', [DataExportController::class, 'exportCollaborateurs'])->middleware('role:super_admin|admin|admin_rh');
        Route::get('export/audit-log', [DataExportController::class, 'exportAuditLog'])->middleware('role:super_admin|admin|admin_rh');
        Route::get('collaborateurs/{collaborateur}/documents-zip', [DataExportController::class, 'downloadCollaborateurDocuments']);
        Route::post('rgpd/delete-collaborateur', [DataExportController::class, 'deleteCollaborateurData'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('rgpd/delete-account', [DataExportController::class, 'requestAccountDeletion'])->middleware('role:super_admin|admin|admin_rh');

        // ── Buddy / Mentor Pairing ─────────────────────────
        Route::get('buddy-pairs', [BuddyPairController::class, 'index'])->middleware('permission:parcours,view');
        Route::get('buddy-pairs/{buddy_pair}', [BuddyPairController::class, 'show'])->middleware('permission:parcours,view');
        Route::post('buddy-pairs', [BuddyPairController::class, 'store'])->middleware('permission:parcours,edit');
        Route::put('buddy-pairs/{buddy_pair}', [BuddyPairController::class, 'update'])->middleware('permission:parcours,edit');
        Route::patch('buddy-pairs/{buddy_pair}', [BuddyPairController::class, 'update'])->middleware('permission:parcours,edit');
        Route::delete('buddy-pairs/{buddy_pair}', [BuddyPairController::class, 'destroy'])->middleware('permission:parcours,edit');
        Route::post('buddy-pairs/{buddy_pair}/note', [BuddyPairController::class, 'addNote'])->middleware('permission:parcours,edit');
        Route::post('buddy-pairs/{buddy_pair}/complete', [BuddyPairController::class, 'complete'])->middleware('permission:parcours,edit');

        // ── Roles & Permissions ────────────────────────────
        Route::apiResource('roles', RoleController::class)->middleware('permission:settings,admin');
        Route::post('roles/{role}/assign', [RoleController::class, 'assignUser'])->middleware('permission:settings,admin');
        Route::post('roles/{role}/remove', [RoleController::class, 'removeUser'])->middleware('permission:settings,admin');
        Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate'])->middleware('permission:settings,admin');
        Route::get('permissions/schema', [RoleController::class, 'permissions'])->middleware('permission:settings,admin');
        Route::get('permissions/effective', [RoleController::class, 'effectivePermissions'])->middleware('permission:settings,admin');
        Route::get('permissions/logs', [RoleController::class, 'logs'])->middleware('permission:settings,admin');

        // ── Subscription management ────────────────────────
        Route::get('my-subscription', [SubscriptionController::class, 'mySubscription']);
        Route::post('subscribe', [SubscriptionController::class, 'subscribe'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->middleware('role:super_admin|admin|admin_rh');
        Route::get('available-plans', [SubscriptionController::class, 'availablePlans']);
        Route::get('active-modules', [SubscriptionController::class, 'activeModules']);
        Route::get('storage-usage', [SubscriptionController::class, 'storageUsage']);
        Route::get('signature-usage', [SubscriptionController::class, 'signatureUsage']);
        Route::get('monthly-consumption', [SubscriptionController::class, 'monthlyConsumption']);

        // ── Invoices ──────────────────────────────────────────
        Route::get('invoices', [SubscriptionController::class, 'listInvoices']);

        // ── OCR / AI (rate-limited) ────────────────────────────
        Route::get('ai/usage', [OcrController::class, 'getUsage']);
        Route::get('ai/quota', [OcrController::class, 'getQuota']);
        Route::middleware('ai.rate')->group(function () {
            Route::post('ocr/identity', [OcrController::class, 'extractIdentity']);
            Route::post('ai/buy-credits', [OcrController::class, 'buyExtraCredits']);
            Route::post('ai/chat', [AiChatController::class, 'sendMessage']);
            Route::post('ai/admin-chat', [AiChatController::class, 'adminChat']);
            Route::post('ai/generate-parcours', [AiChatController::class, 'generateParcours']);
            Route::get('ai/insights', [AiChatController::class, 'getInsights']);
        });

        // ── Calendar ─────────────────────────────────────────────
        Route::get('calendar-events', [CalendarController::class, 'index']);
        Route::get('ai/auto-recharge', [AiChatController::class, 'getAutoRechargeConfig']);
        Route::post('ai/auto-recharge', [AiChatController::class, 'updateAutoRechargeConfig']);
        Route::get('ai/spending-cap', [AiChatController::class, 'getSpendingCap']);
        Route::post('ai/spending-cap', [AiChatController::class, 'updateSpendingCap']);
        Route::post('ai/recharge', [AiChatController::class, 'manualRecharge']);
        Route::post('ai/translate', [AiChatController::class, 'translate']);
        Route::get('ai/recharges', [AiChatController::class, 'getRechargeHistory']);

        // ── Stripe / Payments ─────────────────────────────────
        Route::post('stripe/setup-intent', [StripeController::class, 'createSetupIntent']);
        Route::get('stripe/payment-methods', [StripeController::class, 'getPaymentMethods']);
        Route::post('stripe/default-payment-method', [StripeController::class, 'setDefaultPaymentMethod']);
        Route::post('stripe/delete-payment-method', [StripeController::class, 'deletePaymentMethod']);
        Route::get('payment-config', [StripeController::class, 'getPaymentConfig']);
        Route::post('payment/invoice-config', [StripeController::class, 'saveInvoiceConfig']);
        Route::post('billing/contact', [StripeController::class, 'saveBillingContact']);
        Route::post('billing/info', [StripeController::class, 'saveBillingInfo']);
        Route::get('check-module/{module}', [SubscriptionController::class, 'checkModule']);

        // ── Module Projets ─────────────────────────────────

        // Lecture (permission:projets,view)
        Route::middleware('permission:projets,view')->group(function () {
            Route::get('projets', [ProjetController::class, 'index']);
            Route::get('projets/{projet}', [ProjetController::class, 'show']);
            Route::get('taches', [TacheController::class, 'index']);
            Route::get('taches/{tache}', [TacheController::class, 'show']);
            Route::get('sous-projets', [SousProjetController::class, 'index']);
            Route::get('sous-taches', [SousTacheController::class, 'index']);
            Route::get('commentaires-taches', [CommentaireTacheController::class, 'index']);
            Route::get('jalons', [JalonController::class, 'index']);
            Route::get('lignes-couts', [LigneCoutController::class, 'index']);
            Route::get('taux-horaires', [TauxHoraireController::class, 'index']);
        });

        // Écriture (permission:projets,edit)
        Route::middleware('permission:projets,edit')->group(function () {
            // Projets — CRUD + actions custom
            Route::post('projets', [ProjetController::class, 'store']);
            Route::put('projets/{projet}', [ProjetController::class, 'update']);
            Route::delete('projets/{projet}', [ProjetController::class, 'destroy']);
            Route::patch('projets/{projet}/desactiver', [ProjetController::class, 'desactiver']);
            Route::patch('projets/{projet}/reactiver', [ProjetController::class, 'reactiver']);

            // Membres du projet
            Route::post('projets/{projet}/membres', [ProjetController::class, 'ajouterMembre']);
            Route::delete('projets/{projet}/membres/{user}', [ProjetController::class, 'retirerMembre']);
            Route::patch('projets/{projet}/membres/{user}/role', [ProjetController::class, 'changerRoleMembre']);

            // Tâches — CRUD
            Route::post('taches', [TacheController::class, 'store']);
            Route::put('taches/{tache}', [TacheController::class, 'update']);
            Route::delete('taches/{tache}', [TacheController::class, 'destroy']);

            // Sous-projets — CRUD + réassignation
            Route::post('sous-projets', [SousProjetController::class, 'store']);
            Route::put('sous-projets/{sousProjet}', [SousProjetController::class, 'update']);
            Route::delete('sous-projets/{sousProjet}', [SousProjetController::class, 'destroy']);
            Route::post('sous-projets/{sousProjet}/reassigner-taches', [SousProjetController::class, 'reassignerTaches']);

            // Sous-tâches — CRUD
            Route::post('sous-taches', [SousTacheController::class, 'store']);
            Route::put('sous-taches/{sousTache}', [SousTacheController::class, 'update']);
            Route::delete('sous-taches/{sousTache}', [SousTacheController::class, 'destroy']);

            // Commentaires — CRUD
            Route::post('commentaires-taches', [CommentaireTacheController::class, 'store']);
            Route::put('commentaires-taches/{commentaire}', [CommentaireTacheController::class, 'update']);
            Route::delete('commentaires-taches/{commentaire}', [CommentaireTacheController::class, 'destroy']);

            // Jalons — CRUD
            Route::post('jalons', [JalonController::class, 'store']);
            Route::put('jalons/{jalon}', [JalonController::class, 'update']);
            Route::delete('jalons/{jalon}', [JalonController::class, 'destroy']);

            // Lignes coûts — CRUD
            Route::post('lignes-couts', [LigneCoutController::class, 'store']);
            Route::put('lignes-couts/{ligneCout}', [LigneCoutController::class, 'update']);
            Route::delete('lignes-couts/{ligneCout}', [LigneCoutController::class, 'destroy']);

            // Taux horaires — CRUD
            Route::post('taux-horaires', [TauxHoraireController::class, 'store']);
            Route::put('taux-horaires/{tauxHoraire}', [TauxHoraireController::class, 'update']);
            Route::delete('taux-horaires/{tauxHoraire}', [TauxHoraireController::class, 'destroy']);
        });
    });
});

// DocuSign callback (outside auth — browser redirect from DocuSign)
Route::get('/integrations/docusign/callback', [DocuSignController::class, 'callback']);

// Microsoft SSO callback (outside auth — browser redirect from Microsoft)
Route::middleware([Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::class])->group(function () {
    Route::get('/auth/microsoft/redirect', [EntraIdController::class, 'ssoRedirect']);
});
Route::get('/auth/microsoft/callback', [EntraIdController::class, 'ssoCallback']);

