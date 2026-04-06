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
        $superAdminEmail = env('SUPER_ADMIN_EMAIL');
        $user = $request->user();

        if (!$user || !$superAdminEmail || $user->email !== $superAdminEmail) {
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
            'stripe_publishable_key_set' => !empty(env('STRIPE_KEY')),
            'stripe_secret_key_set' => !empty(env('STRIPE_SECRET')),
            'stripe_webhook_secret_set' => !empty(env('STRIPE_WEBHOOK_SECRET')),
        ]);
    }

    public function updateStripeConfig(Request $request): JsonResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'stripe_key' => 'nullable|string',
            'stripe_secret' => 'nullable|string',
            'stripe_webhook_secret' => 'nullable|string',
        ]);

        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        $mappings = [
            'stripe_key' => 'STRIPE_KEY',
            'stripe_secret' => 'STRIPE_SECRET',
            'stripe_webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
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

        return response()->json(['message' => 'Configuration Stripe mise à jour.']);
    }
}
