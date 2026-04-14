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
use App\Http\Controllers\Api\V1\MessageController;
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
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

// ─── Public (no tenant, no auth) ────────────────────────────
Route::get('/ping', fn () => response()->json(['status' => 'ok', 'app' => 'Illizeo API', 'version' => 'v1']));

// ─── Tenant registration (central, no tenant context) ───────
Route::post('/register-tenant', [TenantRegistrationController::class, 'register']);
Route::post('/check-tenant', [TenantRegistrationController::class, 'checkAvailability']);

// ─── Public pricing (central, no auth) ─────────────────────
Route::get('/plans', [PlanController::class, 'index']);
Route::post('/plans/subscribe', [PlanController::class, 'subscribe']);

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

    // 2FA verify (no auth required — called after login when 2FA is enabled)
    Route::post('2fa/verify', [TwoFactorController::class, 'verify']);

    // NPS public response (no auth needed — anonymous token-based)
    Route::get('nps/respond/{token}', [NpsSurveyController::class, 'getByToken']);
    Route::post('nps/respond/{token}', [NpsSurveyController::class, 'respond']);

    // Protected (auth required)
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Current user permissions
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
            Route::get('stripe-config', [SuperAdminController::class, 'getStripeConfig']);
            Route::put('stripe-config', [SuperAdminController::class, 'updateStripeConfig']);
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

        // ── Contrats ────────────────────────────────────────
        Route::get('contrats', [ContratController::class, 'index'])->middleware('permission:contrats,view');
        Route::post('contrats', [ContratController::class, 'store'])->middleware('permission:contrats,edit');
        Route::get('contrats/{contrat}', [ContratController::class, 'show'])->middleware('permission:contrats,view');
        Route::put('contrats/{contrat}', [ContratController::class, 'update'])->middleware('permission:contrats,edit');
        Route::patch('contrats/{contrat}', [ContratController::class, 'update'])->middleware('permission:contrats,edit');
        Route::delete('contrats/{contrat}', [ContratController::class, 'destroy'])->middleware('permission:contrats,edit');

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
        Route::post('my-actions/{collaborateurAction}/complete', [CollaborateurActionController::class, 'completeMyAction']);

        // Company settings (appearance)
        Route::get('company-settings', [CompanySettingController::class, 'index']);
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
        Route::apiResource('cooptations', CooptationController::class);
        Route::post('cooptations/{cooptation}/mark-hired', [CooptationController::class, 'markHired']);
        Route::post('cooptations/{cooptation}/validate', [CooptationController::class, 'validate']);
        Route::post('cooptations/{cooptation}/mark-rewarded', [CooptationController::class, 'markRewarded']);
        Route::post('cooptations/{cooptation}/refuse', [CooptationController::class, 'refuse']);
        Route::post('cooptations/{cooptation}/upload-cv', [CooptationController::class, 'uploadCV']);
        Route::get('cooptation-stats', [CooptationController::class, 'stats']);
        Route::get('cooptation-settings', [CooptationController::class, 'getSettings']);
        Route::put('cooptation-settings', [CooptationController::class, 'updateSettings']);

        // Cooptation campaigns
        Route::get('cooptation-campaigns', [CooptationController::class, 'listCampaigns']);
        Route::post('cooptation-campaigns', [CooptationController::class, 'createCampaign']);
        Route::put('cooptation-campaigns/{campaign}', [CooptationController::class, 'updateCampaign']);
        Route::delete('cooptation-campaigns/{campaign}', [CooptationController::class, 'deleteCampaign']);
        Route::get('cooptation-leaderboard', [CooptationController::class, 'leaderboard']);

        // ── Badges & Gamification ───────────────────────────
        Route::get('badges', [BadgeController::class, 'earned']);
        Route::get('badges/my', [BadgeController::class, 'myBadges']);
        Route::get('badges/user/{userId}', [BadgeController::class, 'userBadges']);
        Route::get('badge-templates', [BadgeController::class, 'templates']);
        Route::post('badge-templates', [BadgeController::class, 'storeTemplate'])->middleware('role:super_admin|admin|admin_rh');
        Route::put('badge-templates/{badgeTemplate}', [BadgeController::class, 'updateTemplate'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('badge-templates/{badgeTemplate}', [BadgeController::class, 'destroyTemplate'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('badges/award', [BadgeController::class, 'award'])->middleware('role:super_admin|admin|admin_rh');
        Route::delete('badges/{badge}', [BadgeController::class, 'revoke'])->middleware('role:super_admin|admin|admin_rh');

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

        // ── Dossier Validation & SIRH Export ───────────────
        Route::get('collaborateurs/{collaborateur}/dossier-check', [\App\Http\Controllers\Api\V1\DossierController::class, 'check']);
        Route::post('collaborateurs/{collaborateur}/dossier-validate', [\App\Http\Controllers\Api\V1\DossierController::class, 'validate'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('collaborateurs/{collaborateur}/dossier-export', [\App\Http\Controllers\Api\V1\DossierController::class, 'export'])->middleware('role:super_admin|admin|admin_rh');
        Route::post('collaborateurs/{collaborateur}/dossier-reset', [\App\Http\Controllers\Api\V1\DossierController::class, 'reset'])->middleware('role:super_admin|admin|admin_rh');

        // ── Data export & RGPD ─────────────────────────────
        Route::get('export/all', [DataExportController::class, 'exportAll']);
        Route::get('export/collaborateurs', [DataExportController::class, 'exportCollaborateurs']);
        Route::get('export/audit-log', [DataExportController::class, 'exportAuditLog']);
        Route::post('rgpd/delete-collaborateur', [DataExportController::class, 'deleteCollaborateurData']);
        Route::post('rgpd/delete-account', [DataExportController::class, 'requestAccountDeletion']);

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
        Route::get('check-module/{module}', [SubscriptionController::class, 'checkModule']);
    });
});

// DocuSign callback (outside auth — browser redirect from DocuSign)
Route::get('/integrations/docusign/callback', [DocuSignController::class, 'callback']);

// Microsoft SSO callback (outside auth — browser redirect from Microsoft)
Route::middleware([Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::class])->group(function () {
    Route::get('/auth/microsoft/redirect', [EntraIdController::class, 'ssoRedirect']);
});
Route::get('/auth/microsoft/callback', [EntraIdController::class, 'ssoCallback']);

