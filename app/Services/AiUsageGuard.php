<?php

namespace App\Services;

use App\Models\AiRecharge;
use App\Models\AiUsage;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

/**
 * Centralized monthly quota check for all AI features.
 *
 * Flow per AI call (in this order, BEFORE hitting Anthropic):
 *  1. hasActiveAiPlan() — already done by callers, kept here as final fallback
 *  2. AiUsageGuard::blockIfExceeded($type) — returns null if allowed, JsonResponse(402) if blocked
 *  3. Caller proceeds with Anthropic call + AiUsage::create()
 *
 * Quota math:
 *   monthlyLimit = plan->{quota field for $type}  (e.g. ai_bot_messages)
 *   bonusCredits = sum of ai_recharges.credits_added where status='charged' AND created_at in current month
 *                  (recharges are bot_message-style credits for now; one-pool model)
 *   effectiveLimit = monthlyLimit + bonusCredits
 *   usage = AiUsage::monthlyCount($type)
 *   blocked = usage >= effectiveLimit  (only when effectiveLimit > 0)
 *
 * Special: monthlyLimit = 0 means "feature disabled on this plan" → blocked unless bonusCredits > 0.
 * Special: monthlyLimit = null means "unlimited" → never blocked.
 */
class AiUsageGuard
{
    /**
     * Map AiUsage::create($type) values → plan column holding the monthly limit.
     */
    private const TYPE_TO_PLAN_FIELD = [
        'bot_message' => 'ai_bot_messages',
        'ocr_scan' => 'ai_ocr_scans',
        'contrat_generation' => 'ai_contrat_generations',
        // Translation, insights, generate_parcours, cooptation scoring and CV parsing
        // all consume bot-message budget for now (single-pool model).
        'translation' => 'ai_bot_messages',
        'insights' => 'ai_bot_messages',
        'generate_parcours' => 'ai_bot_messages',
        'cooptation_score' => 'ai_bot_messages',
        'cv_parse' => 'ai_bot_messages',
    ];

    /**
     * Returns a structured status for the given AI usage type.
     *
     * @return array{
     *   ok: bool, exceeded: bool, has_plan: bool,
     *   type: string, plan_field: string|null,
     *   used: int, monthly_limit: int|null, bonus_credits: int, effective_limit: int|null, remaining: int|null,
     *   plan_name: string|null,
     * }
     */
    public static function status(string $type): array
    {
        $planField = self::TYPE_TO_PLAN_FIELD[$type] ?? null;
        $aiSub = Subscription::with('plan')
            ->where('tenant_id', tenant()?->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn ($q) => $q->where('addon_type', 'ai'))
            ->first();

        if (!$aiSub) {
            return [
                'ok' => false, 'exceeded' => false, 'has_plan' => false,
                'type' => $type, 'plan_field' => $planField,
                'used' => 0, 'monthly_limit' => 0, 'bonus_credits' => 0, 'effective_limit' => 0, 'remaining' => 0,
                'plan_name' => null,
            ];
        }

        // null in plan = unlimited; 0 = disabled; >0 = capped
        $monthlyLimit = $planField ? ($aiSub->plan->{$planField} ?? null) : null;

        $bonusCredits = (int) AiRecharge::where('status', 'charged')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('credits_added');

        $used = AiUsage::monthlyCount($type);

        // Unlimited path
        if ($monthlyLimit === null) {
            return [
                'ok' => true, 'exceeded' => false, 'has_plan' => true,
                'type' => $type, 'plan_field' => $planField,
                'used' => $used, 'monthly_limit' => null, 'bonus_credits' => $bonusCredits, 'effective_limit' => null, 'remaining' => null,
                'plan_name' => $aiSub->plan->nom ?? null,
            ];
        }

        $effective = (int) $monthlyLimit + $bonusCredits;
        $remaining = max(0, $effective - $used);
        $exceeded = $used >= $effective;

        return [
            'ok' => !$exceeded, 'exceeded' => $exceeded, 'has_plan' => true,
            'type' => $type, 'plan_field' => $planField,
            'used' => $used, 'monthly_limit' => (int) $monthlyLimit, 'bonus_credits' => $bonusCredits, 'effective_limit' => $effective, 'remaining' => $remaining,
            'plan_name' => $aiSub->plan->nom ?? null,
        ];
    }

    /**
     * Returns null if the call should proceed; JsonResponse(402) if blocked.
     * Caller must do `if ($r = AiUsageGuard::blockIfExceeded('bot_message')) return $r;`
     */
    public static function blockIfExceeded(string $type): ?JsonResponse
    {
        $s = self::status($type);

        if (!$s['has_plan']) {
            return response()->json([
                'reply' => "Module IA non activé. Souscrivez un add-on IA pour accéder à cette fonctionnalité.",
                'no_plan' => true,
                'quota_status' => $s,
            ], 402);
        }

        if ($s['exceeded']) {
            return response()->json([
                'reply' => "Quota IA mensuel atteint ({$s['used']}/{$s['effective_limit']}). Vous pouvez acheter des crédits supplémentaires ou passer à un plan supérieur.",
                'quota_exceeded' => true,
                'quota_status' => $s,
            ], 402);
        }

        return null;
    }

    /** Summary across all known types — used by the admin status endpoint. */
    public static function summary(): array
    {
        $byType = [];
        foreach (array_keys(self::TYPE_TO_PLAN_FIELD) as $type) {
            $byType[$type] = self::status($type);
        }
        return [
            'by_type' => $byType,
            'month' => now()->format('Y-m'),
        ];
    }
}
