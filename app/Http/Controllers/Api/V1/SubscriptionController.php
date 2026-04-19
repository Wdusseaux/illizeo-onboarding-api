<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TenantActiveModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class SubscriptionController extends Controller
{
    /**
     * Get current tenant's subscriptions and active modules.
     */
    public function mySubscription(): JsonResponse
    {
        $tenant = tenant();
        $subscriptions = Subscription::where('tenant_id', $tenant->id)
            ->with('plan.modules')
            ->orderBy('created_at')
            ->get();

        $activeModules = TenantActiveModule::where('actif', true)->pluck('module');

        return response()->json([
            'subscriptions' => $subscriptions,
            'active_modules' => $activeModules,
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Subscribe to a plan (can have multiple: one onboarding + one cooptation).
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|integer',
            'billing_cycle' => 'required|in:monthly,yearly',
            'payment_method' => 'required|in:stripe,invoice',
            'nombre_collaborateurs' => 'sometimes|integer|min:25',
        ]);

        $newPlan = Plan::on('central')->with('modules')->findOrFail($request->plan_id);
        $tenant = tenant();
        $isCooptation = $newPlan->slug === 'cooptation';
        $isAi = $newPlan->addon_type === 'ai';

        // Find existing subscription of same category (main plan, cooptation, or AI — each is independent)
        $existingSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', function ($q) use ($isCooptation, $isAi) {
                if ($isCooptation) {
                    $q->where('slug', 'cooptation');
                } elseif ($isAi) {
                    $q->where('addon_type', 'ai');
                } else {
                    $q->where('slug', '!=', 'cooptation')->where(function ($q2) {
                        $q2->whereNull('addon_type')->orWhere('addon_type', '!=', 'ai');
                    })->where(function ($q2) {
                        $q2->where('is_addon', false)->orWhereNull('is_addon');
                    });
                }
            })
            ->with('plan')
            ->first();

        $prorata = 0;
        $isUpgrade = false;
        $isDowngrade = false;
        $effectiveDate = now();
        $wasInTrial = false;

        if ($existingSub) {
            $oldPrice = (float) $existingSub->plan->prix_chf_mensuel;
            $newPrice = (float) $newPlan->prix_chf_mensuel;
            $isUpgrade = $newPrice > $oldPrice;
            $isDowngrade = $newPrice < $oldPrice;
            $wasInTrial = $existingSub->status === 'trialing';

            if ($isUpgrade) {
                // UPGRADE: immediate effect
                if ($isAi) {
                    // AI plans: usage-based billing, no prorata — cumulated for the month
                    $prorata = 0;
                } elseif ($wasInTrial) {
                    // During trial: no prorata (nothing was billed), trial ends now
                    $prorata = 0;
                } else {
                    // Already paying: calculate prorata credit for remaining days
                    $periodStart = \Carbon\Carbon::parse($existingSub->current_period_start);
                    $periodEnd = \Carbon\Carbon::parse($existingSub->current_period_end);
                    $totalDays = $periodStart->diffInDays($periodEnd);
                    $remainingDays = now()->diffInDays($periodEnd);

                    if ($totalDays > 0 && $remainingDays > 0) {
                        $nbCollabs = $existingSub->nombre_collaborateurs ?: 25;
                        $dailyRate = ($oldPrice * $nbCollabs) / $totalDays;
                        $prorata = round($dailyRate * $remainingDays, 2);
                    }
                }

                // Cancel old subscription immediately
                $existingSub->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ]);
            } elseif ($isDowngrade) {
                // DOWNGRADE: current plan stays active until end of period, new plan starts after
                if ($wasInTrial && !$isAi) {
                    // During trial (non-AI): downgrade is immediate (no billing to protect)
                    $existingSub->update([
                        'status' => 'canceled',
                        'canceled_at' => now(),
                    ]);
                } else {
                    // Already paying or AI plan: schedule switch at end of current period
                    $effectiveDate = $existingSub->current_period_end;
                    $existingSub->update([
                        'canceled_at' => $existingSub->current_period_end,
                    ]);
                }
            } else {
                // Same plan
                $wasReactivated = !empty($existingSub->canceled_at);
                $oldCycle = $existingSub->billing_cycle;
                $newCycle = $request->billing_cycle;

                // ── Billing cycle change logic ──
                if ($oldCycle !== $newCycle && !$wasReactivated) {
                    if ($oldCycle === 'monthly' && $newCycle === 'yearly') {
                        // MONTHLY → YEARLY: immediate upgrade, new annual period starts now, -10%
                        // Prorata credit for remaining days of current monthly period
                        if (!$wasInTrial && !$isAi) {
                            $periodStart = \Carbon\Carbon::parse($existingSub->current_period_start);
                            $periodEnd = \Carbon\Carbon::parse($existingSub->current_period_end);
                            $totalDays = $periodStart->diffInDays($periodEnd);
                            $remainingDays = now()->diffInDays($periodEnd);
                            if ($totalDays > 0 && $remainingDays > 0) {
                                $nbCollabs = $existingSub->nombre_collaborateurs ?: 25;
                                $dailyRate = ($oldPrice * $nbCollabs) / $totalDays;
                                $prorata = round($dailyRate * $remainingDays, 2);
                            }
                        }

                        $existingSub->update([
                            'status' => 'canceled',
                            'canceled_at' => now(),
                        ]);

                        // Will create new yearly subscription below
                        $isUpgrade = true;
                    } elseif ($oldCycle === 'yearly' && $newCycle === 'monthly') {
                        // YEARLY → MONTHLY: not allowed immediately, schedule for end of annual period
                        $periodEnd = $existingSub->current_period_end;
                        $formattedEnd = \Carbon\Carbon::parse($periodEnd)->format('d/m/Y');

                        // Check if a pending downgrade already exists
                        $pendingDowngrade = Subscription::where('tenant_id', $tenant->id)
                            ->where('plan_id', $newPlan->id)
                            ->where('status', 'pending')
                            ->first();

                        if ($pendingDowngrade) {
                            return response()->json([
                                'message' => "Un changement vers la facturation mensuelle est déjà programmé pour le {$formattedEnd}.",
                                'subscription' => $existingSub->load('plan'),
                            ]);
                        }

                        // Schedule the cycle change at end of annual period
                        $existingSub->update([
                            'canceled_at' => $periodEnd,
                        ]);

                        $monthlyPeriodEnd = \Carbon\Carbon::parse($periodEnd)->addMonth();
                        Subscription::create([
                            'tenant_id' => $tenant->id,
                            'plan_id' => $newPlan->id,
                            'status' => 'pending',
                            'currency' => 'chf',
                            'billing_cycle' => 'monthly',
                            'current_period_start' => $periodEnd,
                            'current_period_end' => $monthlyPeriodEnd,
                            'nombre_collaborateurs' => $existingSub->nombre_collaborateurs ?: 25,
                        ]);

                        return response()->json([
                            'message' => "Passage en facturation mensuelle programmé pour le {$formattedEnd}. Votre abonnement annuel reste actif jusque-là. La réduction de 10% ne sera plus appliquée après cette date.",
                            'subscription' => $existingSub->load('plan'),
                            'effective_date' => $periodEnd,
                        ]);
                    }
                } else {
                    // Same cycle — just reactivate if canceled
                    $existingSub->update([
                        'billing_cycle' => $request->billing_cycle,
                        'canceled_at' => null,
                    ]);

                    return response()->json([
                        'message' => $wasReactivated ? 'Abonnement réactivé avec succès' : 'Cycle de facturation mis à jour',
                        'subscription' => $existingSub->load('plan'),
                    ]);
                }
            }
        }

        // Create new subscription
        $nbCollabs = $request->nombre_collaborateurs ?: 25;
        $periodEnd = $request->billing_cycle === 'yearly'
            ? \Carbon\Carbon::parse($effectiveDate)->addYear()
            : \Carbon\Carbon::parse($effectiveDate)->addMonth();

        // Determine status:
        // - First subscription ever → trialing (14 days), except AI plans (no trial)
        // - Upgrade/downgrade during trial → active (trial ends)
        // - Upgrade from paid plan → active
        // - Downgrade from paid plan or AI → pending (activates at end of current period)
        $newStatus = $isAi ? 'active' : 'trialing';
        if ($existingSub) {
            if ($isDowngrade && ($isAi || !$wasInTrial)) {
                $newStatus = 'pending';
            } else {
                $newStatus = 'active';
            }
        }

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $newPlan->id,
            'status' => $newStatus,
            'currency' => 'chf',
            'billing_cycle' => $request->billing_cycle,
            'current_period_start' => $effectiveDate,
            'current_period_end' => $periodEnd,
            'trial_ends_at' => $newStatus === 'trialing' ? now()->addDays(14) : null,
            'nombre_collaborateurs' => $nbCollabs,
        ]);

        // Billing is handled by the daily CRON command (billing:process)
        // which creates invoices and charges via PaymentIntent (card/SEPA)
        // No Stripe Subscriptions are used — we manage recurrence ourselves
        // for flexibility (usage-based billing, prorata, employee count changes)

        // Sync active modules (only for upgrade — downgrade keeps old modules until switch)
        if (!$isDowngrade) {
            $this->syncModules($tenant->id);
        }

        // Check if this was a billing cycle upgrade (same plan, monthly → yearly)
        $isCycleUpgrade = $existingSub && $existingSub->plan_id === $newPlan->id && $isUpgrade;

        // Build response
        if ($isUpgrade) {
            if ($isCycleUpgrade) {
                $message = "Passage en facturation annuelle effectué. Vous bénéficiez désormais de 10% de réduction." . ($prorata > 0 ? " Crédit prorata : {$prorata} CHF" : "");
            } elseif ($isAi) {
                $message = "Upgrade vers {$newPlan->nom} effectué. La facturation du plan précédent reste due pour le mois en cours (facturation à l'usage, cumulée pour le mois). Pas de remboursement.";
            } elseif ($wasInTrial) {
                $message = "Upgrade effectué. Votre période d'essai est terminée, le plan {$newPlan->nom} est actif immédiatement.";
            } else {
                $message = "Upgrade effectué immédiatement. Crédit prorata : {$prorata} CHF";
            }
        } elseif ($isDowngrade) {
            if ($isAi) {
                $message = "Downgrade vers {$newPlan->nom} programmé. Le changement prendra effet à la fin de la prochaine facturation le " . \Carbon\Carbon::parse($effectiveDate)->format('d/m/Y') . ".";
            } elseif ($wasInTrial) {
                $message = "Changement de plan effectué immédiatement vers {$newPlan->nom}.";
            } else {
                $message = "Downgrade programmé pour le " . \Carbon\Carbon::parse($effectiveDate)->format('d/m/Y') . ". Votre plan actuel reste actif jusque-là.";
            }
        } else {
            $message = $isAi
                ? "Abonnement {$newPlan->nom} activé. Facturation à l'usage."
                : ($request->payment_method === 'stripe'
                    ? "Abonnement créé — essai gratuit de 14 jours. Prélèvement automatique activé."
                    : "Abonnement créé — essai gratuit de 14 jours");
        }

        return response()->json([
            'message' => $message,
            'subscription' => $subscription->load('plan'),
            'prorata_credit' => $prorata,
            'is_upgrade' => $isUpgrade,
            'is_downgrade' => $isDowngrade,
            'effective_date' => $effectiveDate->toDateString(),
            'stripe_subscription_id' => $stripeSubscriptionId,
        ], 201);
    }

    /**
     * Cancel a subscription.
     */
    public function cancel(Subscription $subscription): JsonResponse
    {
        // Cancel takes effect at the end of the current billing period
        $periodEnd = $subscription->current_period_end;

        if ($periodEnd && \Carbon\Carbon::parse($periodEnd)->isFuture()) {
            // Schedule cancellation at period end — keep active until then
            $subscription->update([
                'canceled_at' => $periodEnd,
            ]);

            $formattedDate = \Carbon\Carbon::parse($periodEnd)->format('d/m/Y');
            return response()->json([
                'message' => "Abonnement annulé. Il restera actif jusqu'au {$formattedDate}.",
                'effective_date' => $periodEnd,
                'immediate' => false,
            ]);
        }

        // Period already ended or no period — cancel immediately
        $subscription->update([
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);

        $this->syncModules(tenant()->id);

        return response()->json([
            'message' => 'Abonnement annulé immédiatement.',
            'immediate' => true,
        ]);
    }

    /**
     * Get available plans for subscription.
     */
    public function availablePlans(): JsonResponse
    {
        $plans = Plan::on('central')->where('actif', true)->with('modules')->orderBy('ordre')->get();

        return response()->json($plans);
    }

    /**
     * Check if a module is active for the current tenant.
     */
    public function checkModule(string $module): JsonResponse
    {
        $active = TenantActiveModule::where('module', $module)->where('actif', true)->exists();

        return response()->json(['module' => $module, 'active' => $active]);
    }

    /**
     * Get all active modules.
     */
    public function activeModules(): JsonResponse
    {
        return response()->json(TenantActiveModule::where('actif', true)->pluck('module'));
    }

    /**
     * List invoices for the current tenant.
     */
    public function listInvoices(): JsonResponse
    {
        $invoices = Invoice::where('tenant_id', tenant('id'))
            ->with('plan:id,nom,slug,addon_type')
            ->orderByDesc('date_emission')
            ->get();

        return response()->json($invoices);
    }

    /**
     * Get storage usage for the current tenant.
     */
    public function storageUsage(): JsonResponse
    {
        $tenantId = tenant('id');
        $basePath = storage_path("app/private/documents");
        $totalBytes = 0;
        $fileCount = 0;

        // Calculate size of all uploaded documents
        if (is_dir($basePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $totalBytes += $file->getSize();
                    $fileCount++;
                }
            }
        }

        // Also count the tenant database file itself
        $dbPath = database_path("tenant_{$tenantId}.sqlite");
        $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;

        $totalBytes += $dbSize;
        $maxBytes = 1 * 1024 * 1024 * 1024; // 1 GB default limit
        $usedPercent = $maxBytes > 0 ? round(($totalBytes / $maxBytes) * 100, 1) : 0;

        return response()->json([
            'used_bytes' => $totalBytes,
            'used_formatted' => $this->formatBytes($totalBytes),
            'max_bytes' => $maxBytes,
            'max_formatted' => $this->formatBytes($maxBytes),
            'percent' => $usedPercent,
            'file_count' => $fileCount,
            'db_size' => $this->formatBytes($dbSize),
        ]);
    }

    /**
     * Get signature usage for the current tenant.
     */
    public function signatureUsage(): JsonResponse
    {
        $total = \App\Models\SignatureLog::count();
        $signed = \App\Models\SignatureLog::where('status', 'signed')->count();
        $sent = \App\Models\SignatureLog::where('status', 'sent')->count();
        $declined = \App\Models\SignatureLog::where('status', 'declined')->count();

        return response()->json([
            'total' => $total,
            'signed' => $signed,
            'sent' => $sent,
            'declined' => $declined,
        ]);
    }

    /**
     * Monthly consumption: list of active users (admins + employees) for billing.
     * Any user who was active (logged in or had activity) during the month is counted.
     */
    public function monthlyConsumption(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $month = (int) ($request->query('month') ?: now()->month);
        $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        // Get all users who existed during this month
        $users = \App\Models\User::where('created_at', '<=', $endOfMonth)
            ->get();

        $activeUsers = [];
        foreach ($users as $user) {
            $collab = $user->collaborateur;
            $isAdmin = $user->hasRole('admin') || $user->hasRole('super_admin') || $user->hasRole('admin_rh');
            $role = $isAdmin ? 'admin' : 'employé';
            $site = $collab?->site ?? '—';
            $prenom = $collab?->prenom ?? explode(' ', $user->name)[0] ?? '—';
            $nom = $collab?->nom ?? (count(explode(' ', $user->name)) > 1 ? implode(' ', array_slice(explode(' ', $user->name), 1)) : '—');

            // Check if user was active this month:
            // 1. Has a token that was used this month
            $hadTokenActivity = $user->tokens()
                ->where('last_used_at', '>=', $startOfMonth)
                ->where('last_used_at', '<=', $endOfMonth)
                ->exists();

            // 2. Was created this month (new user = active)
            $createdThisMonth = $user->created_at >= $startOfMonth && $user->created_at <= $endOfMonth;

            // 3. Has a collaborateur with date_debut in or before this month and not terminated
            $hasActiveCollab = $collab && $collab->date_debut && $collab->date_debut <= $endOfMonth;

            if ($hadTokenActivity || $createdThisMonth || $hasActiveCollab || $isAdmin) {
                $activeUsers[] = [
                    'id' => $user->id,
                    'prenom' => $prenom,
                    'nom' => $nom,
                    'email' => $user->email,
                    'site' => $site,
                    'role' => $role,
                    'departement' => $collab?->departement ?? '—',
                ];
            }
        }

        $adminCount = count(array_filter($activeUsers, fn($u) => $u['role'] === 'admin'));
        $employeeCount = count($activeUsers) - $adminCount;

        return response()->json([
            'year' => $year,
            'month' => $month,
            'month_label' => $startOfMonth->locale('fr')->isoFormat('MMMM YYYY'),
            'total_active' => count($activeUsers),
            'admin_count' => $adminCount,
            'employee_count' => $employeeCount,
            'min_billed' => 25,
            'billed_count' => max(25, count($activeUsers)),
            'users' => $activeUsers,
        ]);
    }

    /**
     * Create a Stripe Subscription for recurring billing.
     */
    private function createStripeSubscription(Subscription $subscription, Plan $plan, int $nbCollabs, string $billingCycle, bool $withTrial): ?string
    {
        $stripe = new StripeClient(config('services.stripe.secret'));

        // Get Stripe customer ID
        $customerId = \App\Models\CompanySetting::where('key', 'stripe_customer_id')->value('value');
        if (!$customerId) {
            return null;
        }

        // Check customer has a default payment method
        $customer = $stripe->customers->retrieve($customerId);
        $defaultPm = $customer->invoice_settings->default_payment_method ?? null;
        if (!$defaultPm) {
            // Try to get any payment method
            $methods = $stripe->paymentMethods->all(['customer' => $customerId, 'type' => 'card', 'limit' => 1]);
            if (count($methods->data) === 0) {
                return null; // No card saved, can't create subscription
            }
            $defaultPm = $methods->data[0]->id;
            $stripe->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $defaultPm],
            ]);
        }

        // Calculate unit price in centimes (Stripe uses smallest currency unit)
        $unitPrice = (float) $plan->prix_chf_mensuel;
        if ($billingCycle === 'yearly') {
            $unitPrice = round($unitPrice * 12 * 0.9, 2); // 10% annual discount
        }
        $totalCentimes = (int) round($unitPrice * $nbCollabs * 100);

        // Use Stripe Price ID if configured, otherwise create an ad-hoc price
        $priceData = null;
        $stripePriceId = $plan->stripe_price_id_chf;

        if (!$stripePriceId) {
            // Create ad-hoc price for this subscription
            $priceData = [
                'currency' => 'chf',
                'unit_amount' => $totalCentimes,
                'recurring' => [
                    'interval' => $billingCycle === 'yearly' ? 'year' : 'month',
                ],
                'product_data' => [
                    'name' => $plan->nom . " ({$nbCollabs} collaborateurs)",
                    'metadata' => [
                        'plan_id' => $plan->id,
                        'plan_slug' => $plan->slug,
                        'nb_collaborateurs' => $nbCollabs,
                    ],
                ],
            ];
        }

        // Build subscription params
        $subParams = [
            'customer' => $customerId,
            'default_payment_method' => $defaultPm,
            'metadata' => [
                'tenant_id' => tenant('id'),
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'local_subscription_id' => $subscription->id,
                'nb_collaborateurs' => $nbCollabs,
            ],
        ];

        if ($priceData) {
            $subParams['items'] = [['price_data' => $priceData]];
        } else {
            $subParams['items'] = [['price' => $stripePriceId, 'quantity' => $nbCollabs]];
        }

        // Add 14-day trial for new subscriptions
        if ($withTrial) {
            $subParams['trial_period_days'] = 14;
        }

        $stripeSub = $stripe->subscriptions->create($subParams);

        // Update local subscription with Stripe IDs
        $subscription->update([
            'stripe_subscription_id' => $stripeSub->id,
            'stripe_customer_id' => $customerId,
        ]);

        return $stripeSub->id;
    }

    /**
     * Handle Stripe webhooks for subscription events.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            if ($webhookSecret) {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            } else {
                $data = json_decode($payload, true);
                $event = (object) $data;
                $event->type = $data['type'] ?? '';
                $event->data = (object) ['object' => (object) ($data['data']['object'] ?? [])];
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Webhook signature verification failed'], 400);
        }

        $object = $event->data->object;

        switch ($event->type) {
            case 'invoice.payment_succeeded':
                // Payment successful — update subscription period
                $stripeSubId = $object->subscription ?? null;
                if ($stripeSubId) {
                    $sub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
                    if ($sub) {
                        $sub->update([
                            'status' => 'active',
                            'current_period_start' => \Carbon\Carbon::createFromTimestamp($object->period_start ?? time()),
                            'current_period_end' => \Carbon\Carbon::createFromTimestamp($object->period_end ?? time()),
                        ]);

                        // Create invoice record
                        \App\Models\Invoice::create([
                            'subscription_id' => $sub->id,
                            'tenant_id' => $sub->tenant_id,
                            'stripe_invoice_id' => $object->id,
                            'montant' => ($object->amount_paid ?? 0) / 100,
                            'currency' => $object->currency ?? 'chf',
                            'status' => 'paid',
                            'date_emission' => now(),
                            'date_echeance' => now(),
                            'pdf_url' => $object->invoice_pdf ?? null,
                        ]);
                    }
                }
                break;

            case 'invoice.payment_failed':
                // Payment failed — mark subscription as past_due
                $stripeSubId = $object->subscription ?? null;
                if ($stripeSubId) {
                    $sub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
                    if ($sub) {
                        $sub->update(['status' => 'past_due']);
                    }
                }
                break;

            case 'customer.subscription.deleted':
                // Subscription canceled in Stripe
                $stripeSubId = $object->id ?? null;
                if ($stripeSubId) {
                    $sub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
                    if ($sub) {
                        $sub->update([
                            'status' => 'canceled',
                            'canceled_at' => now(),
                        ]);
                        $this->syncModules($sub->tenant_id);
                    }
                }
                break;

            case 'customer.subscription.updated':
                // Subscription updated — sync period dates
                $stripeSubId = $object->id ?? null;
                if ($stripeSubId) {
                    $sub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
                    if ($sub) {
                        $updates = [];
                        if (isset($object->current_period_start)) {
                            $updates['current_period_start'] = \Carbon\Carbon::createFromTimestamp($object->current_period_start);
                        }
                        if (isset($object->current_period_end)) {
                            $updates['current_period_end'] = \Carbon\Carbon::createFromTimestamp($object->current_period_end);
                        }
                        if (isset($object->status)) {
                            $statusMap = ['active' => 'active', 'trialing' => 'trialing', 'past_due' => 'past_due', 'canceled' => 'canceled', 'unpaid' => 'past_due'];
                            $updates['status'] = $statusMap[$object->status] ?? $object->status;
                        }
                        if (!empty($updates)) {
                            $sub->update($updates);
                        }
                    }
                }
                break;
        }

        return response()->json(['received' => true]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    /**
     * Sync tenant's active modules based on all active subscriptions.
     */
    private function syncModules(string $tenantId): void
    {
        // Get all active subscription plan modules
        $activeSubscriptions = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan.modules')
            ->get();

        $activeModules = [];
        foreach ($activeSubscriptions as $sub) {
            foreach ($sub->plan->modules as $mod) {
                if ($mod->actif) {
                    $activeModules[$mod->module] = $sub->plan_id;
                }
            }
        }

        // Clear and recreate
        TenantActiveModule::query()->delete();
        foreach ($activeModules as $module => $planId) {
            TenantActiveModule::create([
                'module' => $module,
                'source_plan_id' => $planId,
                'actif' => true,
            ]);
        }
    }
}
