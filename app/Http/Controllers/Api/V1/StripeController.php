<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class StripeController extends Controller
{
    private function stripe(): StripeClient
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $secret = $mode === 'test'
            ? (config('services.stripe.test_secret') ?: env('STRIPE_TEST_SECRET'))
            : (config('services.stripe.live_secret') ?: env('STRIPE_SECRET'));
        return new StripeClient($secret);
    }

    private function getPublishableKey(): string
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        return $mode === 'test'
            ? (config('services.stripe.test_key') ?: env('STRIPE_TEST_KEY'))
            : (config('services.stripe.live_key') ?: env('STRIPE_KEY'));
    }

    /**
     * Get or create a Stripe Customer for the current tenant.
     */
    private function getOrCreateCustomer(): \Stripe\Customer
    {
        $tenant = tenant();
        $stripe = $this->stripe();
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $settingKey = $mode === 'test' ? 'stripe_test_customer_id' : 'stripe_customer_id';

        // Check if tenant already has a Stripe customer ID for this mode
        $customerId = \App\Models\CompanySetting::where('key', $settingKey)->value('value');

        if ($customerId) {
            try {
                return $stripe->customers->retrieve($customerId);
            } catch (\Exception $e) {
                // Customer was deleted in Stripe, create new one
            }
        }

        // Create new customer
        $user = auth()->user();
        $customer = $stripe->customers->create([
            'email' => $user->email,
            'name' => $tenant->nom ?? $tenant->id,
            'metadata' => [
                'app' => 'onboarding',
                'tenant_id' => $tenant->id,
                'mode' => $mode,
            ],
        ]);

        \App\Models\CompanySetting::updateOrCreate(
            ['key' => $settingKey],
            ['value' => $customer->id]
        );

        return $customer;
    }

    /**
     * Create a SetupIntent to save a card for future payments.
     */
    public function createSetupIntent(): JsonResponse
    {
        $customer = $this->getOrCreateCustomer();
        $stripe = $this->stripe();

        $setupIntent = $stripe->setupIntents->create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'metadata' => [
                'app' => 'onboarding',
                'tenant_id' => tenant('id'),
            ],
        ]);

        return response()->json([
            'client_secret' => $setupIntent->client_secret,
            'customer_id' => $customer->id,
            'publishable_key' => $this->getPublishableKey(),
        ]);
    }

    /**
     * Get saved payment methods for the tenant.
     */
    public function getPaymentMethods(): JsonResponse
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $settingKey = $mode === 'test' ? 'stripe_test_customer_id' : 'stripe_customer_id';
        $customerId = \App\Models\CompanySetting::where('key', $settingKey)->value('value');

        if (!$customerId) {
            return response()->json(['methods' => [], 'default' => null]);
        }

        $stripe = $this->stripe();

        try {
            $methods = $stripe->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card',
            ]);

            $customer = $stripe->customers->retrieve($customerId);
            $defaultMethodId = $customer->invoice_settings->default_payment_method ?? null;

            $cards = collect($methods->data)->map(fn($m) => [
                'id' => $m->id,
                'brand' => $m->card->brand,
                'last4' => $m->card->last4,
                'exp_month' => $m->card->exp_month,
                'exp_year' => $m->card->exp_year,
                'is_default' => $m->id === $defaultMethodId,
            ])->toArray();

            return response()->json([
                'methods' => $cards,
                'default' => $defaultMethodId,
                'customer_id' => $customerId,
            ]);
        } catch (\Exception $e) {
            return response()->json(['methods' => [], 'default' => null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Set default payment method after card is saved.
     */
    public function setDefaultPaymentMethod(Request $request): JsonResponse
    {
        $request->validate(['payment_method_id' => 'required|string']);

        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $customerId = \App\Models\CompanySetting::where('key', $mode === 'test' ? 'stripe_test_customer_id' : 'stripe_customer_id')->value('value');
        if (!$customerId) {
            return response()->json(['error' => 'No Stripe customer'], 404);
        }

        $stripe = $this->stripe();

        try {
            $stripe->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $request->payment_method_id],
            ]);

            // Save payment method preference
            \App\Models\CompanySetting::updateOrCreate(
                ['key' => 'payment_method'],
                ['value' => 'stripe']
            );

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Delete a saved payment method.
     */
    public function deletePaymentMethod(Request $request): JsonResponse
    {
        $request->validate(['payment_method_id' => 'required|string']);

        try {
            $this->stripe()->paymentMethods->detach($request->payment_method_id);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Save invoice billing preferences.
     */
    public function saveInvoiceConfig(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_email' => 'sometimes|email',
            'po_number' => 'sometimes|string|max:50',
            'billing_address' => 'sometimes|array',
        ]);

        $settings = [
            'invoice_email' => $request->invoice_email,
            'invoice_po_number' => $request->po_number,
            'payment_method' => 'invoice',
        ];

        if ($request->has('billing_address')) {
            foreach ($request->billing_address as $key => $value) {
                $settings["billing_{$key}"] = $value;
            }
        }

        foreach ($settings as $key => $value) {
            if ($value !== null) {
                \App\Models\CompanySetting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Configuration facture enregistrée']);
    }

    /**
     * Get current payment config (method preference + details).
     */
    public function getPaymentConfig(): JsonResponse
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $customerKey = $mode === 'test' ? 'stripe_test_customer_id' : 'stripe_customer_id';
        $settings = \App\Models\CompanySetting::whereIn('key', [
            'payment_method', 'stripe_customer_id', 'stripe_test_customer_id', 'invoice_email', 'invoice_po_number',
            'billing_contact_prenom', 'billing_contact_nom', 'billing_contact_email', 'billing_contact_telephone', 'billing_contact_pays',
            'billing_company', 'billing_vat', 'billing_rue', 'billing_numero',
            'billing_complement', 'billing_case_postale', 'billing_localite',
            'billing_code_postal', 'billing_ville', 'billing_canton', 'billing_pays',
        ])->pluck('value', 'key');

        return response()->json([
            'payment_method' => $settings['payment_method'] ?? 'invoice',
            'stripe_configured' => !empty($settings[$customerKey]),
            'stripe_mode' => $mode,
            'invoice_email' => $settings['invoice_email'] ?? null,
            'invoice_po_number' => $settings['invoice_po_number'] ?? null,
            'billing_contact' => [
                'prenom' => $settings['billing_contact_prenom'] ?? null,
                'nom' => $settings['billing_contact_nom'] ?? null,
                'email' => $settings['billing_contact_email'] ?? null,
                'telephone' => $settings['billing_contact_telephone'] ?? null,
                'pays' => $settings['billing_contact_pays'] ?? 'Suisse',
            ],
            'billing' => [
                'company' => $settings['billing_company'] ?? null,
                'vat' => $settings['billing_vat'] ?? null,
                'rue' => $settings['billing_rue'] ?? null,
                'numero' => $settings['billing_numero'] ?? null,
                'complement' => $settings['billing_complement'] ?? null,
                'case_postale' => $settings['billing_case_postale'] ?? null,
                'localite' => $settings['billing_localite'] ?? null,
                'code_postal' => $settings['billing_code_postal'] ?? null,
                'ville' => $settings['billing_ville'] ?? null,
                'canton' => $settings['billing_canton'] ?? null,
                'pays' => $settings['billing_pays'] ?? 'Suisse',
            ],
        ]);
    }

    /**
     * Save billing contact info.
     */
    public function saveBillingContact(Request $request): JsonResponse
    {
        $fields = ['prenom', 'nom', 'email', 'telephone', 'pays'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                \App\Models\CompanySetting::updateOrCreate(
                    ['key' => "billing_contact_{$field}"],
                    ['value' => $request->$field]
                );
            }
        }

        // Also update Stripe customer if exists
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $customerId = \App\Models\CompanySetting::where('key', $mode === 'test' ? 'stripe_test_customer_id' : 'stripe_customer_id')->value('value');
        if ($customerId && ($request->has('email') || $request->has('prenom'))) {
            try {
                $this->stripe()->customers->update($customerId, array_filter([
                    'email' => $request->email,
                    'name' => trim(($request->prenom ?? '') . ' ' . ($request->nom ?? '')),
                ]));
            } catch (\Exception $e) {
                // Non-blocking
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Save billing info (company, address, VAT).
     */
    public function saveBillingInfo(Request $request): JsonResponse
    {
        $fields = ['company', 'vat', 'rue', 'numero', 'complement', 'case_postale', 'localite', 'code_postal', 'ville', 'canton', 'pays'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                \App\Models\CompanySetting::updateOrCreate(
                    ['key' => "billing_{$field}"],
                    ['value' => $request->$field]
                );
            }
        }

        return response()->json(['success' => true]);
    }
}
