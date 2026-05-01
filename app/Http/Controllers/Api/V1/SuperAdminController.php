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
        // Email match is sufficient for super admin access
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
            'is_addon' => 'boolean',
            'addon_type' => 'nullable|string|in:ai,cooptation,signature',
            'ai_ocr_scans' => 'nullable|integer|min:0',
            'ai_bot_messages' => 'nullable|integer|min:0',
            'ai_contrat_generations' => 'nullable|integer|min:0',
            'ai_translations' => 'nullable|integer|min:0',
            'ai_model' => 'nullable|string',
            'ai_extra_scan_price_chf' => 'nullable|numeric|min:0',
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
            'is_addon' => 'boolean',
            'addon_type' => 'nullable|string|in:ai,cooptation,signature',
            'ai_ocr_scans' => 'nullable|integer|min:0',
            'ai_bot_messages' => 'nullable|integer|min:0',
            'ai_contrat_generations' => 'nullable|integer|min:0',
            'ai_translations' => 'nullable|integer|min:0',
            'ai_model' => 'nullable|string',
            'ai_extra_scan_price_chf' => 'nullable|numeric|min:0',
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

    public function markInvoicePaid(Request $request, int $invoiceId): JsonResponse
    {
        $this->authorize($request);

        $invoice = Invoice::findOrFail($invoiceId);
        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_error' => null,
        ]);

        return response()->json(['message' => "Facture {$invoice->invoice_number} marquée comme payée", 'invoice' => $invoice]);
    }

    // ─── Stripe Config ──────────────────────────────────────────

    public function getStripeConfig(Request $request): JsonResponse
    {
        $this->authorize($request);

        // Aperçu d'une clé : préfixe (jusqu'au 2e _) + 4 derniers caractères.
        // Permet de vérifier que la clé stockée est bien celle attendue (sk_live_*, sk_test_*, etc.)
        $preview = function ($val) {
            if (empty($val)) return null;
            $len = strlen($val);
            $prefix = substr($val, 0, 12);
            $suffix = $len > 16 ? substr($val, -4) : '';
            return $suffix ? $prefix . '...' . $suffix : $prefix . '...';
        };

        $live_secret = env('STRIPE_SECRET', '');
        $live_key = env('STRIPE_KEY', '');
        $live_webhook = env('STRIPE_WEBHOOK_SECRET', '');
        $test_secret = env('STRIPE_TEST_SECRET', '');
        $test_key = env('STRIPE_TEST_KEY', '');
        $test_webhook = env('STRIPE_TEST_WEBHOOK_SECRET', '');

        // Validation prefix : sk_live_*, sk_test_*, pk_live_*, pk_test_*, whsec_*
        $valid = function ($val, $expected_prefix) {
            return !empty($val) && str_starts_with($val, $expected_prefix);
        };

        return response()->json([
            'mode' => config('services.stripe.mode', 'live'),
            'has_key' => !empty(config('services.stripe.key')),
            'has_secret' => !empty(config('services.stripe.secret')),
            'has_webhook' => !empty(config('services.stripe.webhook_secret')),
            'live_configured' => !empty($live_secret),
            'test_configured' => !empty($test_secret),
            'live' => [
                'key_preview' => $preview($live_key),
                'secret_preview' => $preview($live_secret),
                'webhook_preview' => $preview($live_webhook),
                'key_valid' => $valid($live_key, 'pk_live_'),
                'secret_valid' => $valid($live_secret, 'sk_live_'),
                'webhook_valid' => $valid($live_webhook, 'whsec_'),
            ],
            'test' => [
                'key_preview' => $preview($test_key),
                'secret_preview' => $preview($test_secret),
                'webhook_preview' => $preview($test_webhook),
                'key_valid' => $valid($test_key, 'pk_test_'),
                'secret_valid' => $valid($test_secret, 'sk_test_'),
                'webhook_valid' => $valid($test_webhook, 'whsec_'),
            ],
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

    /**
     * Sync Stripe Price IDs onto local Plan records.
     *
     * Pour chaque Price Stripe actif :
     *   1. On essaie d'identifier le plan local via metadata.illizeo_plan_slug
     *      ou metadata.plan_slug (clé recommandée à mettre côté Stripe).
     *   2. Sinon, on tente un fuzzy match sur le Product.name (ex: "Illizeo Starter"
     *      → plan slug "starter") et on prend le Price récurrent le plus récent
     *      pour chaque (plan × devise).
     * On stocke selon la currency : stripe_price_id_eur / stripe_price_id_chf.
     */
    public function syncStripePrices(Request $request): JsonResponse
    {
        $this->authorize($request);

        $mode = $request->input('mode', config('services.stripe.mode') ?: env('STRIPE_MODE', 'live'));
        $secret = $mode === 'test'
            ? (config('services.stripe.test_secret') ?: env('STRIPE_TEST_SECRET'))
            : (config('services.stripe.live_secret') ?: env('STRIPE_SECRET'));

        if (empty($secret)) {
            return response()->json(['error' => "Aucune clé secrète Stripe configurée pour le mode '{$mode}'."], 422);
        }

        $stripe = new \Stripe\StripeClient($secret);

        // Récupère TOUS les Prices actifs récurrents avec leur Product expandé
        $prices = [];
        $params = ['active' => true, 'expand' => ['data.product'], 'limit' => 100];
        do {
            $page = $stripe->prices->all($params);
            foreach ($page->data as $p) {
                if (($p->type ?? null) !== 'recurring') continue;
                $prices[] = $p;
            }
            $params['starting_after'] = end($page->data)->id ?? null;
        } while (!empty($page->has_more));

        $plans = Plan::all();
        $report = [
            'mode' => $mode,
            'stripe_prices_scanned' => count($prices),
            'plans_count' => $plans->count(),
            'matched' => [],
            'unmatched_plans' => [],
            'unmatched_prices' => [],
        ];

        $matchedPriceIds = [];

        foreach ($plans as $plan) {
            $bestEur = null;
            $bestChf = null;
            foreach ($prices as $price) {
                $product = $price->product;
                $productName = is_object($product) ? ($product->name ?? '') : '';
                $metaSlug = $price->metadata->illizeo_plan_slug
                    ?? $price->metadata->plan_slug
                    ?? (is_object($product) ? ($product->metadata->illizeo_plan_slug ?? $product->metadata->plan_slug ?? null) : null);

                $matches = false;
                if ($metaSlug && strtolower($metaSlug) === strtolower($plan->slug)) {
                    $matches = true;
                } elseif (!$metaSlug) {
                    // Fuzzy match sur le nom du produit
                    $haystack = strtolower($productName);
                    $needle = strtolower($plan->nom);
                    if ($needle && str_contains($haystack, $needle)) {
                        $matches = true;
                    } elseif ($plan->slug && str_contains($haystack, str_replace('_', ' ', $plan->slug))) {
                        $matches = true;
                    }
                }

                if (!$matches) continue;

                $currency = strtolower($price->currency ?? '');
                if ($currency === 'eur' && !$bestEur) $bestEur = $price;
                if ($currency === 'chf' && !$bestChf) $bestChf = $price;
            }

            $update = [];
            if ($bestEur) { $update['stripe_price_id_eur'] = $bestEur->id; $matchedPriceIds[] = $bestEur->id; }
            if ($bestChf) { $update['stripe_price_id_chf'] = $bestChf->id; $matchedPriceIds[] = $bestChf->id; }

            if (!empty($update)) {
                $plan->update($update);
                $report['matched'][] = [
                    'plan_slug' => $plan->slug,
                    'plan_nom' => $plan->nom,
                    'eur_price_id' => $bestEur?->id,
                    'chf_price_id' => $bestChf?->id,
                ];
            } else {
                $report['unmatched_plans'][] = ['plan_slug' => $plan->slug, 'plan_nom' => $plan->nom];
            }
        }

        foreach ($prices as $price) {
            if (in_array($price->id, $matchedPriceIds)) continue;
            $product = $price->product;
            $report['unmatched_prices'][] = [
                'price_id' => $price->id,
                'product_name' => is_object($product) ? ($product->name ?? '?') : '?',
                'currency' => strtoupper($price->currency ?? '?'),
                'amount' => ($price->unit_amount ?? 0) / 100,
                'interval' => $price->recurring->interval ?? '?',
            ];
        }

        return response()->json($report);
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

                    // Convert USD to CHF (approximate rate)
                    $usdToChf = 0.88;
                    $costChf = round($totalCost * $usdToChf, 2);
                    $billedChf = round($costChf * 2, 2); // x2 margin
                    $marginChf = round($billedChf - $costChf, 2);

                    // Token details
                    $totalInputTokens = (int) \App\Models\AiUsage::whereYear('created_at', $year)
                        ->whereMonth('created_at', $month)->sum('input_tokens');
                    $totalOutputTokens = (int) \App\Models\AiUsage::whereYear('created_at', $year)
                        ->whereMonth('created_at', $month)->sum('output_tokens');

                    $result[] = [
                        'tenant_id' => $tenant->id,
                        'tenant_name' => $tenant->nom ?? $tenant->id,
                        'plan_name' => $aiSub->plan->nom,
                        'plan_price_chf' => $aiSub->plan->prix_chf_mensuel ?? 0,
                        'ocr_scans' => $ocrScans,
                        'ocr_limit' => $aiSub->plan->ai_ocr_scans ?? 0,
                        'bot_messages' => $botMessages,
                        'bot_limit' => $aiSub->plan->ai_bot_messages ?? 0,
                        'contrat_generations' => $contratGens,
                        'contrat_limit' => $aiSub->plan->ai_contrat_generations ?? 0,
                        'total_cost_usd' => $totalCost,
                        'cost_chf' => $costChf,
                        'billed_chf' => $billedChf,
                        'margin_chf' => $marginChf,
                        'input_tokens' => $totalInputTokens,
                        'output_tokens' => $totalOutputTokens,
                    ];
                });
            } catch (\Exception $e) {
                // Skip tenants that fail
            }
        }

        return response()->json($result);
    }
}
