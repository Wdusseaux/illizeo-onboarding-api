<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TenantActiveModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        // Find existing subscription of same category
        $existingSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn ($q) => $isCooptation
                ? $q->where('slug', 'cooptation')
                : $q->where('slug', '!=', 'cooptation'))
            ->with('plan')
            ->first();

        $prorata = 0;
        $isUpgrade = false;
        $isDowngrade = false;
        $effectiveDate = now();

        if ($existingSub) {
            $oldPrice = (float) $existingSub->plan->prix_chf_mensuel;
            $newPrice = (float) $newPlan->prix_chf_mensuel;
            $isUpgrade = $newPrice > $oldPrice;
            $isDowngrade = $newPrice < $oldPrice;

            if ($isUpgrade) {
                // UPGRADE: immediate effect + prorata credit
                $periodStart = \Carbon\Carbon::parse($existingSub->current_period_start);
                $periodEnd = \Carbon\Carbon::parse($existingSub->current_period_end);
                $totalDays = $periodStart->diffInDays($periodEnd);
                $remainingDays = now()->diffInDays($periodEnd);

                if ($totalDays > 0 && $remainingDays > 0) {
                    $nbCollabs = $existingSub->nombre_collaborateurs ?: 25;
                    $dailyRate = ($oldPrice * $nbCollabs) / $totalDays;
                    $prorata = round($dailyRate * $remainingDays, 2);
                }

                // Cancel old subscription immediately
                $existingSub->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ]);
            } elseif ($isDowngrade) {
                // DOWNGRADE: takes effect at end of current period
                $effectiveDate = $existingSub->current_period_end;

                // Schedule the old sub to end at period end, mark new plan as pending
                $existingSub->update([
                    'canceled_at' => $existingSub->current_period_end,
                ]);
            } else {
                // Same plan — reactivate if canceled, or update billing cycle
                $wasReactivated = !empty($existingSub->canceled_at);
                $existingSub->update([
                    'billing_cycle' => $request->billing_cycle,
                    'canceled_at' => null, // Clear any scheduled cancellation
                ]);

                return response()->json([
                    'message' => $wasReactivated ? 'Abonnement réactivé avec succès' : 'Cycle de facturation mis à jour',
                    'subscription' => $existingSub->load('plan'),
                ]);
            }
        }

        // Create new subscription
        $nbCollabs = $request->nombre_collaborateurs ?: 25;
        $periodEnd = $request->billing_cycle === 'yearly'
            ? \Carbon\Carbon::parse($effectiveDate)->addYear()
            : \Carbon\Carbon::parse($effectiveDate)->addMonth();

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $newPlan->id,
            'status' => $isDowngrade ? 'pending' : ($existingSub ? 'active' : 'trialing'),
            'currency' => 'chf',
            'billing_cycle' => $request->billing_cycle,
            'current_period_start' => $effectiveDate,
            'current_period_end' => $periodEnd,
            'trial_ends_at' => $existingSub ? null : now()->addDays(14),
            'nombre_collaborateurs' => $nbCollabs,
        ]);

        // Sync active modules (only for upgrade — downgrade keeps old modules until switch)
        if (!$isDowngrade) {
            $this->syncModules($tenant->id);
        }

        // Build response
        $message = $isUpgrade
            ? "Upgrade effectué immédiatement. Crédit prorata : {$prorata} CHF"
            : ($isDowngrade
                ? "Downgrade programmé pour le " . \Carbon\Carbon::parse($effectiveDate)->format('d/m/Y')
                : "Abonnement créé — essai gratuit de 14 jours");

        return response()->json([
            'message' => $message,
            'subscription' => $subscription->load('plan'),
            'prorata_credit' => $prorata,
            'is_upgrade' => $isUpgrade,
            'is_downgrade' => $isDowngrade,
            'effective_date' => $effectiveDate->toDateString(),
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
