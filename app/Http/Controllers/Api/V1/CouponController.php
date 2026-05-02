<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin back-office for managing Stripe Coupons + Promotion Codes.
 *
 * Stripe model:
 * - Coupon = the discount definition (% off or fixed amount, duration)
 * - Promotion Code = the user-facing string (SUMMER25) attached to a Coupon
 *
 * Stripe Checkout Session has `allow_promotion_codes: true` — codes created
 * here are automatically usable at checkout.
 */
class CouponController extends Controller
{
    private function stripe(): \Stripe\StripeClient
    {
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $secret = $mode === 'test'
            ? (config('services.stripe.test_secret') ?: env('STRIPE_TEST_SECRET'))
            : (config('services.stripe.live_secret') ?: env('STRIPE_SECRET'));
        return new \Stripe\StripeClient($secret);
    }

    /**
     * List all coupons + their promotion codes.
     */
    public function index(): JsonResponse
    {
        try {
            $stripe = $this->stripe();
            $coupons = $stripe->coupons->all(['limit' => 100]);
            $promoCodes = $stripe->promotionCodes->all(['limit' => 100]);

            // Group promo codes by coupon id
            $codesByCoupon = [];
            foreach ($promoCodes->data as $code) {
                $cid = $code->coupon->id ?? null;
                if (!$cid) continue;
                $codesByCoupon[$cid][] = [
                    'id' => $code->id,
                    'code' => $code->code,
                    'active' => $code->active,
                    'times_redeemed' => $code->times_redeemed,
                    'max_redemptions' => $code->max_redemptions,
                    'expires_at' => $code->expires_at ? date('c', $code->expires_at) : null,
                    'created' => date('c', $code->created),
                ];
            }

            $list = [];
            foreach ($coupons->data as $c) {
                $list[] = [
                    'id' => $c->id,
                    'name' => $c->name,
                    'percent_off' => $c->percent_off,
                    'amount_off' => $c->amount_off,
                    'currency' => $c->currency,
                    'duration' => $c->duration, // once / repeating / forever
                    'duration_in_months' => $c->duration_in_months,
                    'max_redemptions' => $c->max_redemptions,
                    'times_redeemed' => $c->times_redeemed,
                    'redeem_by' => $c->redeem_by ? date('c', $c->redeem_by) : null,
                    'valid' => $c->valid,
                    'created' => date('c', $c->created),
                    'metadata' => (array) $c->metadata,
                    'promotion_codes' => $codesByCoupon[$c->id] ?? [],
                ];
            }

            return response()->json($list);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a coupon + (optionally) a promotion code in one call.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'discount_type' => 'required|in:percent,amount',
            'percent_off' => 'required_if:discount_type,percent|nullable|numeric|min:1|max:100',
            'amount_off' => 'required_if:discount_type,amount|nullable|integer|min:1', // cents
            'currency' => 'required_if:discount_type,amount|nullable|string|size:3',
            'duration' => 'required|in:once,repeating,forever',
            'duration_in_months' => 'required_if:duration,repeating|nullable|integer|min:1|max:120',
            'max_redemptions' => 'nullable|integer|min:1',
            'redeem_by' => 'nullable|date',
            'promo_code' => 'nullable|string|min:3|max:50|regex:/^[A-Za-z0-9_-]+$/',
            'code_max_redemptions' => 'nullable|integer|min:1',
            'code_expires_at' => 'nullable|date',
        ]);

        try {
            $stripe = $this->stripe();

            $couponData = [
                'name' => $validated['name'],
                'duration' => $validated['duration'],
            ];
            if ($validated['discount_type'] === 'percent') {
                $couponData['percent_off'] = (float) $validated['percent_off'];
            } else {
                $couponData['amount_off'] = (int) $validated['amount_off'];
                $couponData['currency'] = strtolower($validated['currency']);
            }
            if ($validated['duration'] === 'repeating') {
                $couponData['duration_in_months'] = (int) $validated['duration_in_months'];
            }
            if (!empty($validated['max_redemptions'])) {
                $couponData['max_redemptions'] = (int) $validated['max_redemptions'];
            }
            if (!empty($validated['redeem_by'])) {
                $couponData['redeem_by'] = strtotime($validated['redeem_by']);
            }

            $coupon = $stripe->coupons->create($couponData);

            $promoCode = null;
            if (!empty($validated['promo_code'])) {
                $promoData = [
                    'coupon' => $coupon->id,
                    'code' => strtoupper($validated['promo_code']),
                ];
                if (!empty($validated['code_max_redemptions'])) {
                    $promoData['max_redemptions'] = (int) $validated['code_max_redemptions'];
                }
                if (!empty($validated['code_expires_at'])) {
                    $promoData['expires_at'] = strtotime($validated['code_expires_at']);
                }
                $promoCode = $stripe->promotionCodes->create($promoData);
            }

            return response()->json([
                'coupon_id' => $coupon->id,
                'promo_code' => $promoCode?->code,
                'promo_code_id' => $promoCode?->id,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Delete a coupon (revokes all attached promotion codes).
     */
    public function destroy(string $couponId): JsonResponse
    {
        try {
            $this->stripe()->coupons->delete($couponId);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Activate / deactivate a promotion code.
     * (Coupon stays — but the user-facing code becomes unusable.)
     */
    public function togglePromoCode(Request $request, string $promoCodeId): JsonResponse
    {
        $validated = $request->validate(['active' => 'required|boolean']);
        try {
            $code = $this->stripe()->promotionCodes->update($promoCodeId, [
                'active' => $validated['active'],
            ]);
            return response()->json(['code' => $code->code, 'active' => $code->active]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
