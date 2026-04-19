<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminController extends Controller
{
    /**
     * Verify the authenticated user is a platform super admin.
     */
    private function authorize(Request $request): void
    {
        $superAdminEmails = array_map('trim', explode(',', env('SUPER_ADMIN_EMAIL', '')));
        $user = $request->user();

        if (!$user || empty($superAdminEmails[0]) || !in_array($user->email, $superAdminEmails)) {
            abort(403, 'Accès réservé au super administrateur de la plateforme.');
        }

        // Also verify the user has an admin role in their tenant
        if (!$user->hasAnyRole(['super_admin', 'admin', 'admin_rh'])) {
            abort(403, 'Rôle insuffisant.');
        }
    }

    // ─── Dashboard ──────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize($request);

        $totalTenants = Tenant::count();
        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $totalCollaborateurs = Subscription::where('status', 'active')->sum('nombre_collaborateurs');

        // MRR: sum of (prix * nombre_collaborateurs) for active monthly subs
        $mrr = Subscription::where('status', 'active')
            ->where('billing_cycle', 'monthly')
            ->with('plan')
            ->get()
            ->sum(function ($sub) {
                $price = $sub->currency === 'chf'
                    ? $sub->plan->prix_chf_mensuel
                    : $sub->plan->prix_eur_mensuel;
                return $price * $sub->nombre_collaborateurs;
            });

        return response()->json([
            'total_tenants' => $totalTenants,
            'active_subscriptions' => $activeSubscriptions,
            'mrr' => round($mrr, 2),
            'total_collaborateurs' => (int) $totalCollaborateurs,
        ]);
    }

    // ─── Tenants ────────────────────────────────────────────────

    public function listTenants(Request $request): JsonResponse
    {
        $this->authorize($request);

        $tenants = Tenant::with(['planRelation', 'subscription'])
            ->get()
            ->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'nom' => $tenant->nom,
                    'slug' => $tenant->slug,
                    'plan' => $tenant->plan,
                    'plan_details' => $tenant->planRelation,
                    'actif' => $tenant->actif,
                    'billing_email' => $tenant->billing_email,
                    'subscription_status' => $tenant->subscription?->status,
                    'nombre_collaborateurs' => $tenant->subscription?->nombre_collaborateurs ?? 0,
                    'created_at' => $tenant->created_at,
                ];
            });

        return response()->json($tenants);
    }

    public function showTenant(Request $request, string $tenantId): JsonResponse
    {
        $this->authorize($request);

        $tenant = Tenant::with(['planRelation', 'subscription.plan', 'invoices'])->findOrFail($tenantId);

        return response()->json($tenant);
    }

    public function updateTenant(Request $request, string $tenantId): JsonResponse
    {
        $this->authorize($request);

        $tenant = Tenant::findOrFail($tenantId);

        $validated = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'plan' => 'sometimes|string',
            'plan_id' => 'sometimes|nullable|integer|exists:plans,id',
            'actif' => 'sometimes|boolean',
            'billing_email' => 'sometimes|nullable|email',
            'trial_ends_at' => 'sometimes|nullable|date',
        ]);

        $tenant->update($validated);

        return response()->json([
            'message' => 'Tenant mis à jour.',
            'tenant' => $tenant->fresh(['planRelation', 'subscription']),
        ]);
    }

    public function deleteTenant(Request $request, string $tenantId): JsonResponse
    {
        $this->authorize($request);

        $tenant = Tenant::findOrFail($tenantId);

        // Delete related subscriptions and invoices first
        Subscription::where('tenant_id', $tenantId)->delete();
        Invoice::where('tenant_id', $tenantId)->delete();

        $tenant->delete();

        return response()->json(['message' => 'Tenant supprimé.']);
    }

    // ─── Plans ──────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(Plan::with('modules')->orderBy('ordre')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:plans,slug',
            'description' => 'nullable|string',
            'prix_eur_mensuel' => 'required|numeric|min:0',
            'prix_chf_mensuel' => 'required|numeric|min:0',
            'min_mensuel_eur' => 'nullable|numeric|min:0',
            'min_mensuel_chf' => 'nullable|numeric|min:0',
            'max_collaborateurs' => 'nullable|integer|min:1',
            'max_admins' => 'nullable|integer|min:1',
            'max_parcours' => 'nullable|integer|min:1',
            'max_integrations' => 'nullable|integer|min:1',
            'max_workflows' => 'nullable|integer|min:1',
            'stripe_price_id_eur' => 'nullable|string',
            'stripe_price_id_chf' => 'nullable|string',
            'actif' => 'boolean',
            'populaire' => 'boolean',
            'ordre' => 'integer',
        ]);

        $plan = Plan::create($validated);

        return response()->json([
            'message' => 'Plan créé.',
            'plan' => $plan->load('modules'),
        ], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:plans,slug,' . $plan->id,
            'description' => 'nullable|string',
            'prix_eur_mensuel' => 'sometimes|numeric|min:0',
            'prix_chf_mensuel' => 'sometimes|numeric|min:0',
            'min_mensuel_eur' => 'nullable|numeric|min:0',
            'min_mensuel_chf' => 'nullable|numeric|min:0',
            'max_collaborateurs' => 'nullable|integer|min:1',
            'max_admins' => 'nullable|integer|min:1',
            'max_parcours' => 'nullable|integer|min:1',
            'max_integrations' => 'nullable|integer|min:1',
            'max_workflows' => 'nullable|integer|min:1',
            'stripe_price_id_eur' => 'nullable|string',
            'stripe_price_id_chf' => 'nullable|string',
            'actif' => 'boolean',
            'populaire' => 'boolean',
            'ordre' => 'integer',
        ]);

        $plan->update($validated);

        return response()->json([
            'message' => 'Plan mis à jour.',
            'plan' => $plan->fresh('modules'),
        ]);
    }

    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        $this->authorize($request);

        if ($plan->subscriptions()->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer un plan avec des abonnements actifs.',
            ], 422);
        }

        $plan->modules()->delete();
        $plan->delete();

        return response()->json(['message' => 'Plan supprimé.']);
    }

    // ─── Modules ────────────────────────────────────────────────

    public function listModules(Request $request, Plan $plan): JsonResponse
    {
        $this->authorize($request);

        return response()->json($plan->modules);
    }

    public function updateModules(Request $request, Plan $plan): JsonResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'modules' => 'required|array',
            'modules.*.module' => 'required|string',
            'modules.*.actif' => 'required|boolean',
            'modules.*.config' => 'nullable|array',
        ]);

        // Sync: delete old modules and create new ones
        $plan->modules()->delete();

        foreach ($validated['modules'] as $moduleData) {
            $plan->modules()->create($moduleData);
        }

        return response()->json([
            'message' => 'Modules mis à jour.',
            'modules' => $plan->fresh()->modules,
        ]);
    }

    // ─── Subscriptions ──────────────────────────────────────────

    public function listSubscriptions(Request $request): JsonResponse
    {
        $this->authorize($request);

        $subscriptions = Subscription::with(['plan', 'tenant'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($subscriptions);
    }

    // ─── Invoices ───────────────────────────────────────────────

    public function listInvoices(Request $request): JsonResponse
    {
        $this->authorize($request);

        $invoices = Invoice::with('subscription.plan')
            ->orderByDesc('date_emission')
            ->get();

        return response()->json($invoices);
    }

    // ─── Stripe Config ──────────────────────────────────────────

    public function getStripeConfig(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json([
            'mode' => config('services.stripe.mode', 'live'),
            'has_key' => !empty(config('services.stripe.key')),
            'has_secret' => !empty(config('services.stripe.secret')),
            'has_webhook' => !empty(config('services.stripe.webhook_secret')),
            'live_configured' => !empty(config('services.stripe.live_secret')),
            'test_configured' => !empty(config('services.stripe.test_secret')),
        ]);
    }

    public function updateStripeConfig(Request $request): JsonResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'stripe_key' => 'nullable|string',
            'stripe_secret' => 'nullable|string',
            'stripe_webhook_secret' => 'nullable|string',
            'stripe_test_key' => 'nullable|string',
            'stripe_test_secret' => 'nullable|string',
            'stripe_test_webhook_secret' => 'nullable|string',
            'stripe_mode' => 'nullable|in:live,test',
        ]);

        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        $mappings = [
            'stripe_key' => 'STRIPE_KEY',
            'stripe_secret' => 'STRIPE_SECRET',
            'stripe_webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
            'stripe_test_key' => 'STRIPE_TEST_KEY',
            'stripe_test_secret' => 'STRIPE_TEST_SECRET',
            'stripe_test_webhook_secret' => 'STRIPE_TEST_WEBHOOK_SECRET',
            'stripe_mode' => 'STRIPE_MODE',
        ];

        foreach ($mappings as $inputKey => $envKey) {
            if (!isset($validated[$inputKey])) {
                continue;
            }

            $value = $validated[$inputKey];

            if (preg_match("/^{$envKey}=.*/m", $envContent)) {
                $envContent = preg_replace("/^{$envKey}=.*/m", "{$envKey}={$value}", $envContent);
            } else {
                $envContent .= "\n{$envKey}={$value}";
            }
        }

        file_put_contents($envPath, $envContent);

        // Clear config cache so new values take effect
        \Artisan::call('config:clear');

        return response()->json(['message' => 'Configuration Stripe mise à jour.']);
    }

    // ── AI / Claude Configuration ────────────────────────────

    public function getAiConfig(): JsonResponse
    {
        $key = env('ANTHROPIC_API_KEY', '');
        $model = config('services.anthropic.model', 'claude-opus-4-6');

        return response()->json([
            'key_set' => !empty($key),
            'key_preview' => $key ? substr($key, -6) : '',
            'model' => $model,
        ]);
    }

    public function updateAiConfig(Request $request): JsonResponse
    {
        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        if ($request->has('api_key') && $request->api_key) {
            $newKey = $request->api_key;
            if (str_contains($envContent, 'ANTHROPIC_API_KEY=')) {
                $envContent = preg_replace('/ANTHROPIC_API_KEY=.*/', "ANTHROPIC_API_KEY={$newKey}", $envContent);
            } else {
                $envContent .= "\nANTHROPIC_API_KEY={$newKey}";
            }
        }

        if ($request->has('model') && $request->model) {
            $newModel = $request->model;
            if (str_contains($envContent, 'ANTHROPIC_MODEL=')) {
                $envContent = preg_replace('/ANTHROPIC_MODEL=.*/', "ANTHROPIC_MODEL={$newModel}", $envContent);
            } else {
                $envContent .= "\nANTHROPIC_MODEL={$newModel}";
            }
        }

        file_put_contents($envPath, $envContent);

        // Clear config cache
        \Artisan::call('config:clear');

        return response()->json(['message' => 'Configuration IA mise à jour.']);
    }

    public function getAiUsage(): JsonResponse
    {
        $tenants = Tenant::all();
        $result = [];

        foreach ($tenants as $tenant) {
            try {
                $tenant->run(function () use ($tenant, &$result) {
                    // Check if tenant has AI subscription
                    $aiSub = Subscription::where('tenant_id', $tenant->id)
                        ->whereIn('status', ['active', 'trialing'])
                        ->whereHas('plan', fn($q) => $q->where('addon_type', 'ai'))
                        ->with('plan')
                        ->first();

                    if (!$aiSub) return;

                    $year = now()->year;
                    $month = now()->month;

                    $ocrScans = \App\Models\AiUsage::where('type', 'ocr_scan')
                        ->whereYear('created_at', $year)->whereMonth('created_at', $month)->count();
                    $botMessages = \App\Models\AiUsage::where('type', 'bot_message')
                        ->whereYear('created_at', $year)->whereMonth('created_at', $month)->count();
                    $contratGens = \App\Models\AiUsage::where('type', 'contrat_generation')
                        ->whereYear('created_at', $year)->whereMonth('created_at', $month)->count();
                    $totalCost = (float) \App\Models\AiUsage::whereYear('created_at', $year)
                        ->whereMonth('created_at', $month)->sum('cost_usd');

                    $result[] = [
                        'tenant_id' => $tenant->id,
                        'tenant_name' => $tenant->nom ?? $tenant->id,
                        'plan_name' => $aiSub->plan->nom,
                        'ocr_scans' => $ocrScans,
                        'ocr_limit' => $aiSub->plan->ai_ocr_scans ?? 0,
                        'bot_messages' => $botMessages,
                        'bot_limit' => $aiSub->plan->ai_bot_messages ?? 0,
                        'contrat_generations' => $contratGens,
                        'contrat_limit' => $aiSub->plan->ai_contrat_generations ?? 0,
                        'total_cost_usd' => $totalCost,
                    ];
                });
            } catch (\Exception $e) {
                // Skip tenants that fail
            }
        }

        return response()->json($result);
    }
}
