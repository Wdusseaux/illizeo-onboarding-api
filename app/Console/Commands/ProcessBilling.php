<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Stripe\StripeClient;

class ProcessBilling extends Command
{
    protected $signature = 'billing:process {--dry-run : Show what would be done without actually doing it}';
    protected $description = 'Process recurring billing: create invoices, charge payments, renew subscriptions, expire trials';

    private function stripe(): StripeClient
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $secret = $mode === 'test'
            ? (config('services.stripe.test_secret') ?: env('STRIPE_TEST_SECRET'))
            : (config('services.stripe.live_secret') ?: env('STRIPE_SECRET'));
        return new StripeClient($secret);
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $now = now();

        $this->info("Processing billing at {$now->toDateTimeString()}" . ($dryRun ? ' [DRY RUN]' : ''));

        // 1. Expire trials
        $this->expireTrials($now, $dryRun);

        // 2. Activate pending subscriptions (downgrades scheduled for today)
        $this->activatePendingSubscriptions($now, $dryRun);

        // 3. Process billing for active subscriptions at period end
        $this->processBillingCycle($now, $dryRun);

        // 4. Retry failed payments
        $this->retryFailedPayments($now, $dryRun);

        $this->info('Billing processing complete.');
        return 0;
    }

    /**
     * Step 1: Expire trials that have ended.
     */
    private function expireTrials(Carbon $now, bool $dryRun): void
    {
        $expiredTrials = Subscription::where('status', 'trialing')
            ->where('trial_ends_at', '<=', $now)
            ->with('plan')
            ->get();

        foreach ($expiredTrials as $sub) {
            $this->line("  Trial expired: tenant={$sub->tenant_id} plan={$sub->plan->nom}");

            if (!$dryRun) {
                // Move to active — first billing will happen
                $sub->update([
                    'status' => 'active',
                    'current_period_start' => $now->toDateString(),
                    'current_period_end' => $sub->billing_cycle === 'yearly'
                        ? $now->copy()->addYear()->toDateString()
                        : $now->copy()->addMonth()->toDateString(),
                ]);

                // Create first invoice
                $this->createInvoice($sub);
            }
        }

        $this->info("  Expired trials: {$expiredTrials->count()}");
    }

    /**
     * Step 2: Activate pending subscriptions (downgrades).
     */
    private function activatePendingSubscriptions(Carbon $now, bool $dryRun): void
    {
        $pending = Subscription::where('status', 'pending')
            ->where('current_period_start', '<=', $now->toDateString())
            ->with('plan')
            ->get();

        foreach ($pending as $sub) {
            $this->line("  Activating pending: tenant={$sub->tenant_id} plan={$sub->plan->nom}");

            if (!$dryRun) {
                // Find and cancel the old active sub for the same category
                $oldSub = Subscription::where('tenant_id', $sub->tenant_id)
                    ->where('id', '!=', $sub->id)
                    ->where('status', 'active')
                    ->whereHas('plan', function ($q) use ($sub) {
                        if ($sub->plan->addon_type === 'ai') {
                            $q->where('addon_type', 'ai');
                        } elseif ($sub->plan->slug === 'cooptation') {
                            $q->where('slug', 'cooptation');
                        } else {
                            $q->where('slug', '!=', 'cooptation')
                              ->where(fn($q2) => $q2->whereNull('addon_type')->orWhere('addon_type', '!=', 'ai'));
                        }
                    })
                    ->first();

                if ($oldSub) {
                    $oldSub->update(['status' => 'canceled', 'canceled_at' => $now]);
                }

                $sub->update(['status' => 'active']);

                // Sync modules
                $this->syncModules($sub->tenant_id);

                // Create first invoice for the new plan
                $this->createInvoice($sub);
            }
        }

        $this->info("  Activated pending: {$pending->count()}");
    }

    /**
     * Step 3: Process billing for active subscriptions at period end.
     */
    private function processBillingCycle(Carbon $now, bool $dryRun): void
    {
        $dueSubscriptions = Subscription::where('status', 'active')
            ->where('current_period_end', '<=', $now->toDateString())
            ->whereNull('canceled_at')
            ->with('plan')
            ->get();

        $renewed = 0;
        foreach ($dueSubscriptions as $sub) {
            $this->line("  Renewing: tenant={$sub->tenant_id} plan={$sub->plan->nom}");

            if (!$dryRun) {
                // Renew the subscription period
                $newStart = Carbon::parse($sub->current_period_end);
                $newEnd = $sub->billing_cycle === 'yearly'
                    ? $newStart->copy()->addYear()
                    : $newStart->copy()->addMonth();

                $sub->update([
                    'current_period_start' => $newStart->toDateString(),
                    'current_period_end' => $newEnd->toDateString(),
                ]);

                // Create invoice and charge
                $this->createInvoice($sub);
                $renewed++;
            }
        }

        // Also handle canceled_at subscriptions that have reached their end
        $canceledDue = Subscription::where('status', 'active')
            ->whereNotNull('canceled_at')
            ->where('canceled_at', '<=', $now->toDateString())
            ->get();

        foreach ($canceledDue as $sub) {
            $this->line("  Canceling at period end: tenant={$sub->tenant_id}");
            if (!$dryRun) {
                $sub->update(['status' => 'canceled']);
                $this->syncModules($sub->tenant_id);
            }
        }

        $this->info("  Renewed: {$renewed}, Canceled: {$canceledDue->count()}");
    }

    /**
     * Step 4: Retry failed payments (max 3 attempts, 3 days apart).
     */
    private function retryFailedPayments(Carbon $now, bool $dryRun): void
    {
        $failedInvoices = Invoice::where('status', 'failed')
            ->where('payment_attempts', '<', 3)
            ->where('payment_method', '!=', 'invoice')
            ->where(function ($q) use ($now) {
                $q->whereNull('last_payment_attempt')
                  ->orWhere('last_payment_attempt', '<=', $now->copy()->subDays(3));
            })
            ->get();

        foreach ($failedInvoices as $invoice) {
            $this->line("  Retrying payment: invoice={$invoice->invoice_number} attempt={$invoice->payment_attempts + 1}");
            if (!$dryRun) {
                $this->chargeInvoice($invoice);
            }
        }

        $this->info("  Retried payments: {$failedInvoices->count()}");
    }

    /**
     * Create an invoice for a subscription.
     */
    private function createInvoice(Subscription $sub): Invoice
    {
        $plan = $sub->plan;
        $isAi = $plan->addon_type === 'ai';

        // Calculate amount
        $nbCollabs = $sub->nombre_collaborateurs ?: 25;
        $pricePerUnit = (float) $plan->prix_chf_mensuel;

        if ($isAi) {
            $montantHt = $pricePerUnit; // Fixed price
        } else {
            $montantHt = $pricePerUnit * $nbCollabs;
            if ($sub->billing_cycle === 'yearly') {
                $montantHt *= 0.9; // 10% discount
            }
        }

        // Determine TVA based on tenant's billing country
        $tauxTva = $this->getTenantTvaRate($sub->tenant_id);
        $montantTva = round($montantHt * $tauxTva / 100, 2);
        $montantTtc = round($montantHt + $montantTva, 2);

        // Get payment method preference
        $paymentMethod = $this->getTenantPaymentMethod($sub->tenant_id);

        // Billing snapshot
        $billingSnapshot = $this->getBillingSnapshot($sub->tenant_id);

        $invoice = Invoice::create([
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'tenant_id' => $sub->tenant_id,
            'subscription_id' => $sub->id,
            'plan_id' => $plan->id,
            'montant_ht' => $montantHt,
            'taux_tva' => $tauxTva,
            'montant_tva' => $montantTva,
            'montant_ttc' => $montantTtc,
            'currency' => 'chf',
            'payment_method' => $paymentMethod,
            'nombre_collaborateurs' => $nbCollabs,
            'billing_cycle' => $sub->billing_cycle,
            'period_start' => $sub->current_period_start,
            'period_end' => $sub->current_period_end,
            'status' => 'draft',
            'date_emission' => now()->toDateString(),
            'date_echeance' => $paymentMethod === 'invoice'
                ? now()->addDays(30)->toDateString()
                : now()->toDateString(),
            'billing_snapshot' => $billingSnapshot,
        ]);

        $this->line("    Invoice created: {$invoice->invoice_number} = {$montantTtc} CHF");

        // Charge immediately for card/sepa
        if ($paymentMethod !== 'invoice') {
            $this->chargeInvoice($invoice);
        } else {
            $invoice->update(['status' => 'sent']);
        }

        return $invoice;
    }

    /**
     * Charge an invoice via Stripe (card or SEPA).
     */
    private function chargeInvoice(Invoice $invoice): void
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $customerKey = $mode === 'test' ? 'stripe_test_customer_id' : 'stripe_customer_id';

        // Get Stripe customer ID for tenant
        tenancy()->initialize(\App\Models\Tenant::find($invoice->tenant_id));
        $customerId = \App\Models\CompanySetting::where('key', $customerKey)->value('value');
        tenancy()->end();

        if (!$customerId) {
            $invoice->update([
                'status' => 'failed',
                'payment_error' => 'No Stripe customer configured',
                'payment_attempts' => $invoice->payment_attempts + 1,
                'last_payment_attempt' => now(),
            ]);
            $this->error("    No Stripe customer for tenant {$invoice->tenant_id}");
            return;
        }

        try {
            $stripe = $this->stripe();

            // Get default payment method
            $customer = $stripe->customers->retrieve($customerId);
            $defaultPaymentMethod = $customer->invoice_settings->default_payment_method ?? null;

            if (!$defaultPaymentMethod) {
                throw new \Exception('No default payment method configured');
            }

            // Create PaymentIntent
            $amountInCents = (int) round($invoice->montant_ttc * 100);
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => $amountInCents,
                'currency' => $invoice->currency,
                'customer' => $customerId,
                'payment_method' => $defaultPaymentMethod,
                'off_session' => true,
                'confirm' => true,
                'description' => "Illizeo - {$invoice->invoice_number}",
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'tenant_id' => $invoice->tenant_id,
                ],
            ]);

            if ($paymentIntent->status === 'succeeded') {
                $invoice->update([
                    'status' => 'paid',
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'paid_at' => now(),
                    'payment_attempts' => $invoice->payment_attempts + 1,
                    'last_payment_attempt' => now(),
                    'payment_error' => null,
                ]);
                $this->line("    Payment succeeded: {$invoice->invoice_number}");
            } else {
                // SEPA might be 'processing' — will be confirmed via webhook
                $invoice->update([
                    'status' => $paymentIntent->status === 'processing' ? 'processing' : 'failed',
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'payment_attempts' => $invoice->payment_attempts + 1,
                    'last_payment_attempt' => now(),
                ]);
                $this->line("    Payment status: {$paymentIntent->status}");
            }
        } catch (\Stripe\Exception\CardException $e) {
            $invoice->update([
                'status' => 'failed',
                'payment_error' => $e->getMessage(),
                'payment_attempts' => $invoice->payment_attempts + 1,
                'last_payment_attempt' => now(),
            ]);
            $this->error("    Card error: {$e->getMessage()}");
        } catch (\Exception $e) {
            $invoice->update([
                'status' => 'failed',
                'payment_error' => $e->getMessage(),
                'payment_attempts' => $invoice->payment_attempts + 1,
                'last_payment_attempt' => now(),
            ]);
            $this->error("    Payment error: {$e->getMessage()}");
        }
    }

    /**
     * Get TVA rate for a tenant based on billing country.
     */
    private function getTenantTvaRate(string $tenantId): float
    {
        tenancy()->initialize(\App\Models\Tenant::find($tenantId));
        $pays = \App\Models\CompanySetting::where('key', 'billing_contact_pays')->value('value')
            ?? \App\Models\CompanySetting::where('key', 'billing_pays')->value('value')
            ?? 'Suisse';
        tenancy()->end();

        $paysLower = strtolower($pays);
        if (str_contains($paysLower, 'suisse') || str_contains($paysLower, 'switzerland') || strtoupper($pays) === 'CH') {
            return 8.1;
        }
        return 0;
    }

    /**
     * Get tenant payment method preference.
     */
    private function getTenantPaymentMethod(string $tenantId): string
    {
        tenancy()->initialize(\App\Models\Tenant::find($tenantId));
        $method = \App\Models\CompanySetting::where('key', 'payment_method')->value('value') ?? 'invoice';
        tenancy()->end();
        return $method;
    }

    /**
     * Get billing snapshot for invoice.
     */
    private function getBillingSnapshot(string $tenantId): array
    {
        tenancy()->initialize(\App\Models\Tenant::find($tenantId));
        $settings = \App\Models\CompanySetting::whereIn('key', [
            'billing_contact_prenom', 'billing_contact_nom', 'billing_contact_email',
            'billing_contact_telephone', 'billing_contact_pays',
            'billing_company', 'billing_vat', 'billing_rue', 'billing_numero',
            'billing_code_postal', 'billing_ville', 'billing_canton', 'billing_pays',
        ])->pluck('value', 'key')->toArray();
        tenancy()->end();
        return $settings;
    }

    /**
     * Sync active modules for a tenant.
     */
    private function syncModules(string $tenantId): void
    {
        $activeSubs = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan.modules')
            ->get();

        $modules = [];
        foreach ($activeSubs as $sub) {
            foreach ($sub->plan->modules as $mod) {
                if ($mod->actif) {
                    $modules[$mod->module] = true;
                }
            }
        }

        tenancy()->initialize(\App\Models\Tenant::find($tenantId));
        \App\Models\TenantActiveModule::query()->delete();
        foreach (array_keys($modules) as $module) {
            \App\Models\TenantActiveModule::create(['module' => $module, 'actif' => true]);
        }
        tenancy()->end();
    }
}
