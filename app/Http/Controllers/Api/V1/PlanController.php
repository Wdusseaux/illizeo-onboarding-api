<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * List active plans with their modules (public, no auth).
     */
    public function index(): JsonResponse
    {
        $plans = Plan::where('actif', true)
            ->with('modules')
            ->orderBy('ordre')
            ->get();

        return response()->json($plans);
    }

    /**
     * Create a Stripe checkout session for a plan.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
            'currency' => 'required|in:eur,chf',
            'billing_cycle' => 'required|in:monthly,yearly',
            'tenant_id' => 'required|string|exists:tenants,id',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        $stripePriceId = $validated['currency'] === 'chf'
            ? $plan->stripe_price_id_chf
            : $plan->stripe_price_id_eur;

        if (!$stripePriceId) {
            return response()->json([
                'message' => 'Ce plan n\'a pas encore de prix Stripe configuré pour cette devise.',
            ], 422);
        }

        // Stripe integration placeholder — requires stripe/stripe-php
        // For now, return the plan details for the frontend to handle
        return response()->json([
            'message' => 'Stripe checkout non encore configuré. Utilisez le panel super-admin pour configurer les clés Stripe.',
            'plan' => $plan,
            'stripe_price_id' => $stripePriceId,
            'currency' => $validated['currency'],
            'billing_cycle' => $validated['billing_cycle'],
        ]);
    }
}
