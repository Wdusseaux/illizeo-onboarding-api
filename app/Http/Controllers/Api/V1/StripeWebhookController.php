<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Mail\InvoicePaymentFailedMail;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class StripeWebhookController extends Controller
{
    /**
     * Handle Stripe webhook events.
     */
    public function handle(Request $request): JsonResponse
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $webhookSecret = $mode === 'test'
            ? (config('services.stripe.test_webhook') ?: env('STRIPE_TEST_WEBHOOK_SECRET'))
            : (config('services.stripe.live_webhook') ?: env('STRIPE_WEBHOOK_SECRET'));

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            \Log::warning('Stripe webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            \Log::warning('Stripe webhook error: ' . $e->getMessage());
            return response()->json(['error' => 'Webhook error'], 400);
        }

        \Log::info("Stripe webhook received: {$event->type}", ['id' => $event->id]);

        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
            'charge.dispute.created' => $this->handleDisputeCreated($event->data->object),
            'charge.refunded' => $this->handleRefund($event->data->object),
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
            'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($event->data->object),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event->data->object),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    /**
     * Payment succeeded (card immediate or SEPA confirmed after processing).
     */
    private function handlePaymentSucceeded($paymentIntent): void
    {
        $invoiceId = $paymentIntent->metadata->invoice_id ?? null;
        if (!$invoiceId) return;

        $invoice = Invoice::find($invoiceId);
        if (!$invoice || $invoice->status === 'paid') return;

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
            'stripe_payment_intent_id' => $paymentIntent->id,
            'payment_error' => null,
        ]);

        \Log::info("Invoice {$invoice->invoice_number} marked as paid via webhook");
    }

    /**
     * Payment failed.
     */
    private function handlePaymentFailed($paymentIntent): void
    {
        $invoiceId = $paymentIntent->metadata->invoice_id ?? null;
        if (!$invoiceId) return;

        $invoice = Invoice::find($invoiceId);
        if (!$invoice) return;

        $error = $paymentIntent->last_payment_error->message ?? 'Payment failed';

        $invoice->update([
            'status' => 'failed',
            'payment_error' => $error,
        ]);

        \Log::warning("Invoice {$invoice->invoice_number} payment failed: {$error}");
    }

    /**
     * Dispute/chargeback created.
     */
    private function handleDisputeCreated($dispute): void
    {
        $paymentIntentId = $dispute->payment_intent ?? null;
        if (!$paymentIntentId) return;

        $invoice = Invoice::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (!$invoice) return;

        $invoice->update([
            'status' => 'disputed',
            'payment_error' => "Contestation reçue: {$dispute->reason}",
        ]);

        \Log::warning("Invoice {$invoice->invoice_number} disputed: {$dispute->reason}");
    }

    /**
     * Refund processed.
     */
    private function handleRefund($charge): void
    {
        $paymentIntentId = $charge->payment_intent ?? null;
        if (!$paymentIntentId) return;

        $invoice = Invoice::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (!$invoice) return;

        $invoice->update(['status' => 'refunded']);
        \Log::info("Invoice {$invoice->invoice_number} refunded");
    }

    /**
     * Self-service Checkout Session completed: create the local Subscription.
     */
    private function handleCheckoutCompleted($session): void
    {
        $tenantId = $session->metadata->tenant_id ?? null;
        $planId = $session->metadata->plan_id ?? null;
        $billingCycle = $session->metadata->billing_cycle ?? 'monthly';

        if (!$tenantId || !$planId) {
            \Log::warning("checkout.session.completed without tenant_id/plan_id metadata", ['session' => $session->id]);
            return;
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            \Log::warning("Checkout for unknown tenant: {$tenantId}");
            return;
        }

        $stripeSubscriptionId = $session->subscription ?? null;
        $stripeCustomerId = $session->customer ?? null;

        // Avoid duplicates
        $existing = \App\Models\Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
        if ($existing) {
            \Log::info("Subscription already exists for Stripe sub {$stripeSubscriptionId}");
            return;
        }

        try {
            $plan = \App\Models\Plan::find($planId);
            $currency = strtolower($session->currency ?? 'chf');
            $quantity = (int) ($session->metadata->nombre_collaborateurs ?? 25);

            $priceField = "prix_{$currency}_mensuel";
            $unitMonthly = (float) ($plan->{$priceField} ?? $plan->prix_mensuel ?? 0);
            $monthly = $plan->addon_type === 'ai' ? $unitMonthly : $unitMonthly * $quantity;
            $amountHt = $billingCycle === 'yearly' ? $monthly * 12 * 0.9 : $monthly;

            \App\Models\Subscription::create([
                'tenant_id' => $tenantId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'currency' => strtoupper($currency),
                'status' => 'active',
                'started_at' => now(),
                'nombre_collaborateurs' => $quantity,
                'amount_ht_cents' => (int) round($amountHt * 100),
                'stripe_subscription_id' => $stripeSubscriptionId,
                'stripe_customer_id' => $stripeCustomerId,
                'country' => $tenant->country,
                'customer_type' => $tenant->customer_type,
                'vat_number' => $tenant->vat_number,
                'vat_validation_status' => $tenant->vat_validation_status,
            ]);

            // Sync tenant's stripe_customer_id
            if ($stripeCustomerId && !$tenant->stripe_customer_id) {
                $tenant->update(['stripe_customer_id' => $stripeCustomerId, 'plan_id' => $planId]);
            }

            \Log::info("Subscription created via Checkout for tenant {$tenantId}, plan {$planId}");
        } catch (\Throwable $e) {
            \Log::error("Failed to create subscription from checkout session {$session->id}: " . $e->getMessage());
        }
    }

    /**
     * Stripe subscription cancelled: mark local subscription as cancelled.
     */
    private function handleSubscriptionDeleted($stripeSub): void
    {
        $sub = \App\Models\Subscription::where('stripe_subscription_id', $stripeSub->id)->first();
        if (!$sub) return;

        $sub->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ended_at' => now(),
        ]);

        \Log::info("Subscription {$sub->id} cancelled via Stripe");
    }

    /**
     * Stripe Invoice paid — for recurring subscription billing.
     * Marks the matching local Invoice as paid (or creates one if absent).
     */
    private function handleInvoicePaymentSucceeded($stripeInvoice): void
    {
        $stripeSubscriptionId = $stripeInvoice->subscription ?? null;
        $stripeCustomerId = $stripeInvoice->customer ?? null;

        // Find local subscription
        $subscription = $stripeSubscriptionId
            ? \App\Models\Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first()
            : null;

        // Try to match by stripe_invoice_id
        $invoice = Invoice::where('stripe_invoice_id', $stripeInvoice->id)->first();

        if ($invoice) {
            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_error' => null,
            ]);
            // Reactivate subscription if it was past_due
            if ($subscription && in_array($subscription->status, ['past_due', 'unpaid'])) {
                $subscription->update(['status' => 'active']);
            }
            $this->generateAndEmailInvoice($invoice);
            \Log::info("Invoice {$invoice->invoice_number} paid via Stripe invoice {$stripeInvoice->id}");
            return;
        }

        // No local invoice yet — create one if we have a subscription context
        if (!$subscription) {
            \Log::info("Invoice payment succeeded but no matching local subscription", ['stripe_invoice' => $stripeInvoice->id]);
            return;
        }

        try {
            $amountTtc = ($stripeInvoice->amount_paid ?? 0) / 100;
            $amountHt = ($stripeInvoice->subtotal ?? 0) / 100;
            $tax = ($stripeInvoice->tax ?? 0) / 100;

            $newInvoice = Invoice::create([
                'invoice_number' => Invoice::generateInvoiceNumber(),
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'stripe_invoice_id' => $stripeInvoice->id,
                'stripe_payment_intent_id' => $stripeInvoice->payment_intent ?? null,
                'montant_ht' => $amountHt,
                'taux_tva' => $amountHt > 0 ? round($tax / $amountHt * 100, 2) : 0,
                'montant_tva' => $tax,
                'montant_ttc' => $amountTtc,
                'currency' => strtoupper($stripeInvoice->currency ?? 'chf'),
                'payment_method' => 'stripe',
                'nombre_collaborateurs' => $subscription->nombre_collaborateurs ?? 1,
                'billing_cycle' => $subscription->billing_cycle ?? 'monthly',
                'period_start' => isset($stripeInvoice->period_start) ? \Carbon\Carbon::createFromTimestamp($stripeInvoice->period_start) : now(),
                'period_end' => isset($stripeInvoice->period_end) ? \Carbon\Carbon::createFromTimestamp($stripeInvoice->period_end) : now()->addMonth(),
                'status' => 'paid',
                'date_emission' => now(),
                'date_echeance' => now(),
                'paid_at' => now(),
            ]);

            $this->generateAndEmailInvoice($newInvoice);
            \Log::info("Invoice {$newInvoice->invoice_number} created from Stripe invoice {$stripeInvoice->id}");
        } catch (\Throwable $e) {
            \Log::error("Failed to create local invoice from Stripe invoice {$stripeInvoice->id}: " . $e->getMessage());
        }
    }

    /**
     * Generate the PDF for an invoice and email it to the billing contact.
     * Idempotent — can be called multiple times safely.
     */
    private function generateAndEmailInvoice(Invoice $invoice): void
    {
        try {
            // Generate (or regenerate) the PDF
            $pdfService = new InvoicePdfService();
            $pdfPath = $pdfService->generate($invoice);

            // Resolve recipient email
            $email = $this->resolveBillingEmail($invoice);
            if (!$email) {
                \Log::warning("Cannot email invoice {$invoice->invoice_number}: no billing email");
                return;
            }

            Mail::to($email)->send(new InvoiceMail($invoice, $pdfPath));
            \Log::info("Invoice {$invoice->invoice_number} emailed to {$email}");
        } catch (\Throwable $e) {
            \Log::error("Failed to email invoice {$invoice->invoice_number}: " . $e->getMessage());
        }
    }

    private function resolveBillingEmail(Invoice $invoice): ?string
    {
        $snapshot = $invoice->billing_snapshot ?? [];
        $email = $snapshot['billing_contact_email'] ?? null;
        if ($email) return $email;

        $tenant = \App\Models\Tenant::find($invoice->tenant_id);
        if ($tenant?->billing_email) return $tenant->billing_email;

        // Fallback to Stripe customer email if available
        try {
            if (!empty($invoice->stripe_invoice_id)) {
                $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
                $stripeInv = $stripe->invoices->retrieve($invoice->stripe_invoice_id);
                if (!empty($stripeInv->customer_email)) return $stripeInv->customer_email;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * Stripe Invoice payment failed — mark failed, send dunning email,
     * and suspend access after MAX_PAYMENT_ATTEMPTS.
     */
    private function handleInvoicePaymentFailed($stripeInvoice): void
    {
        $invoice = Invoice::where('stripe_invoice_id', $stripeInvoice->id)->first();
        if (!$invoice) return;

        $error = 'Le paiement a été refusé.';
        if (!empty($stripeInvoice->last_finalization_error->message)) {
            $error = $stripeInvoice->last_finalization_error->message;
        } elseif (!empty($stripeInvoice->last_payment_error->message)) {
            $error = $stripeInvoice->last_payment_error->message;
        }

        $attempts = ($invoice->payment_attempts ?? 0) + 1;
        $maxAttempts = 3;
        $accessSuspended = $attempts >= $maxAttempts;

        $invoice->update([
            'status' => 'failed',
            'payment_error' => $error,
            'payment_attempts' => $attempts,
            'last_payment_attempt' => now(),
        ]);

        // After N failed attempts, mark the subscription past_due — frontend
        // hasActiveSub check will fail and the lock screen will kick in.
        if ($accessSuspended && $invoice->subscription_id) {
            $sub = \App\Models\Subscription::find($invoice->subscription_id);
            if ($sub && $sub->status !== 'cancelled') {
                $sub->update(['status' => 'past_due']);
            }
        }

        // Send dunning email
        try {
            $email = $this->resolveBillingEmail($invoice);
            if ($email) {
                $tenantId = $invoice->tenant_id;
                $appUrl = config('app.frontend_url') ?: env('FRONTEND_URL', 'https://onboarding.illizeo.com');
                $portalUrl = "{$appUrl}/{$tenantId}/admin/abonnement";

                Mail::to($email)->send(new InvoicePaymentFailedMail(
                    invoice: $invoice,
                    attemptNumber: $attempts,
                    accessSuspended: $accessSuspended,
                    errorMessage: $error,
                    portalUrl: $portalUrl,
                ));
                \Log::info("Dunning email sent to {$email} for invoice {$invoice->invoice_number} (attempt {$attempts}/{$maxAttempts})");
            }
        } catch (\Throwable $e) {
            \Log::error("Failed to send dunning email for invoice {$invoice->invoice_number}: " . $e->getMessage());
        }

        \Log::warning("Invoice {$invoice->invoice_number} payment failed: {$error} (attempt {$attempts}/{$maxAttempts}" . ($accessSuspended ? ", access suspended" : "") . ")");
    }

    /**
     * Stripe subscription updated (status change, cancel_at_period_end, period rolled, etc.).
     */
    private function handleSubscriptionUpdated($stripeSub): void
    {
        $sub = \App\Models\Subscription::where('stripe_subscription_id', $stripeSub->id)->first();
        if (!$sub) return;

        $update = [];

        // Status mapping
        if (in_array($stripeSub->status, ['active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired'], true)) {
            $update['status'] = $stripeSub->status === 'canceled' ? 'cancelled' : $stripeSub->status;
        }

        // cancel_at_period_end flag
        $update['cancel_at_period_end'] = !empty($stripeSub->cancel_at_period_end);

        // Period dates (refreshed every renewal)
        if (!empty($stripeSub->current_period_start)) {
            $update['current_period_start'] = \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_start);
        }
        if (!empty($stripeSub->current_period_end)) {
            $update['current_period_end'] = \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end);
        }

        // Next payment amount (sum of subscription items)
        if (isset($stripeSub->items->data) && is_array($stripeSub->items->data)) {
            $totalCents = 0;
            foreach ($stripeSub->items->data as $item) {
                $unit = (int) ($item->price->unit_amount ?? 0);
                $qty = (int) ($item->quantity ?? 1);
                $totalCents += $unit * $qty;
            }
            if ($totalCents > 0) {
                $update['next_payment_amount_cents'] = $totalCents;
            }
        }

        $sub->update($update);
    }
}
