<?php

namespace App\Traits;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

trait ChecksPlanLimits
{
    /**
     * Get the current main plan for the tenant.
     * Returns the Plan model or null if no active subscription.
     */
    protected function getTenantPlan()
    {
        $tenantId = tenant('id');

        $subscription = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', function ($q) {
                $q->where(function ($q2) {
                    $q2->where('is_addon', false)
                        ->orWhereNull('is_addon');
                })
                ->whereNull('addon_type')
                ->where('slug', '!=', 'cooptation');
            })
            ->with('plan')
            ->first();

        return $subscription?->plan;
    }

    /**
     * Check if a plan limit is exceeded.
     *
     * @param  string  $limitColumn  e.g. 'max_parcours'
     * @param  int     $currentCount Current count of resources
     * @param  string  $resourceLabel Human label for the error message
     * @return JsonResponse|null  Returns a 403 response if limit exceeded, null if OK
     */
    protected function checkPlanLimit(string $limitColumn, int $currentCount, string $resourceLabel): ?JsonResponse
    {
        $plan = $this->getTenantPlan();

        if (!$plan) {
            return null; // No plan found, allow (graceful fallback)
        }

        $max = $plan->{$limitColumn};

        if ($max === null) {
            return null; // Unlimited
        }

        if ($currentCount >= $max) {
            return response()->json([
                'message' => "Limite de {$resourceLabel} atteinte pour votre plan ({$currentCount}/{$max}). Passez à un plan supérieur.",
            ], 403);
        }

        return null;
    }
}
