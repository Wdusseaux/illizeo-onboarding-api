<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
}
