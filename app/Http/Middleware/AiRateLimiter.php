<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use App\Models\AiUsage;
use App\Models\CompanySetting;
use App\Services\NotificationService;

class AiRateLimiter
{
    /**
     * AI usage protection middleware.
     * Applies rate limiting, spending caps, and anti-loop detection.
     */
    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();
        if (!$tenant) return $next($request);

        $tenantId = $tenant->id;

        try {
            $cache = Cache::store('file');
        } catch (\Exception $e) {
            // If file cache not available, skip rate limiting
            return $next($request);
        }

        // ── 1. Anti-loop: max 10 calls per 60 seconds ──
        $minuteKey = "ai_rate_{$tenantId}_" . floor(time() / 60);
        $minuteCount = (int) $cache->get($minuteKey, 0);

        if ($minuteCount >= 10) {
            Log::warning("AI rate limit hit for tenant {$tenantId}", ['count' => $minuteCount]);
            return response()->json([
                'reply' => 'Trop de requêtes IA. Veuillez patienter quelques instants avant de réessayer.',
                'error' => 'rate_limit',
                'retry_after' => 60 - (time() % 60),
            ], 429);
        }

        $cache->put($minuteKey, $minuteCount + 1, 120);

        // ── 2. Cooldown: if >20 calls in last 5 minutes, block for 5 minutes ──
        $cooldownKey = "ai_cooldown_{$tenantId}";
        $unblockAt = $cache->get($cooldownKey);
        if ($unblockAt) {
            $remaining = $unblockAt - time();
            if ($remaining > 0) {
                return response()->json([
                    'reply' => "Protection anti-boucle activée. Réessayez dans {$remaining} secondes.",
                    'error' => 'cooldown',
                    'retry_after' => $remaining,
                ], 429);
            }
            $cache->forget($cooldownKey);
        }

        $fiveMinKey = "ai_5min_{$tenantId}_" . floor(time() / 300);
        $fiveMinCount = (int) $cache->get($fiveMinKey, 0);
        $cache->put($fiveMinKey, $fiveMinCount + 1, 600);

        if ($fiveMinCount >= 20) {
            $cache->put($cooldownKey, time() + 300, 300);
            Log::error("AI cooldown triggered for tenant {$tenantId}", ['5min_count' => $fiveMinCount]);
            return response()->json([
                'reply' => 'Consommation IA anormale détectée. Blocage temporaire de 5 minutes.',
                'error' => 'cooldown',
                'retry_after' => 300,
            ], 429);
        }

        // ── 3. Monthly spending cap ──
        $aiSub = \App\Models\Subscription::where('status', 'active')
            ->orWhere('status', 'trialing')
            ->whereHas('plan', fn($q) => $q->where('addon_type', 'ai'))
            ->with('plan')->first();
        $defaultCap = (float) ($aiSub?->plan?->prix_chf_mensuel ?? 29);
        $spendingCap = (float) CompanySetting::get('ai_monthly_spending_cap_chf', $defaultCap);
        if ($spendingCap > 0) {
            $year = now()->year;
            $month = now()->month;
            $monthlySpend = (float) AiUsage::whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->sum('cost_usd');
            $monthlySpendChf = $monthlySpend * 0.88; // USD to CHF
            $billedChf = $monthlySpendChf * 2; // x2 margin

            $percentUsed = ($billedChf / $spendingCap) * 100;

            // Send warning notification at 80% (once per day)
            if ($percentUsed >= 80 && $percentUsed < 100) {
                $warningKey = "ai_cap_warning_80_{$tenantId}_" . now()->format('Y_m_d');
                if (!$cache->get($warningKey)) {
                    $cache->put($warningKey, true, 86400);
                    NotificationService::notifyAdmins('ai_cap_warning', 'Plafond IA bientôt atteint',
                        "Votre consommation IA atteint " . round($percentUsed) . "% du plafond (" . round($billedChf, 2) . " CHF / {$spendingCap} CHF).",
                        'alert', '#F9A825', ['percent' => $percentUsed, 'cap_chf' => $spendingCap, 'current_chf' => round($billedChf, 2)]);
                }
            }

            if ($billedChf >= $spendingCap) {
                // Send cap reached notification (once per day)
                $reachedKey = "ai_cap_reached_{$tenantId}_" . now()->format('Y_m_d');
                if (!$cache->get($reachedKey)) {
                    $cache->put($reachedKey, true, 86400);
                    NotificationService::notifyAdmins('ai_cap_reached', 'Plafond IA atteint',
                        "Votre plafond de dépense IA de {$spendingCap} CHF est atteint. Les fonctionnalités IA sont temporairement bloquées.",
                        'alert', '#E53935', ['cap_chf' => $spendingCap]);
                }

                Log::warning("AI spending cap reached for tenant {$tenantId}", [
                    'billed_chf' => $billedChf,
                    'cap_chf' => $spendingCap,
                ]);
                return response()->json([
                    'reply' => "Plafond de dépense IA atteint ce mois (" . round($billedChf, 2) . " CHF / {$spendingCap} CHF). Contactez votre administrateur pour augmenter le plafond ou achetez des crédits supplémentaires.",
                    'error' => 'spending_cap',
                    'billed_chf' => round($billedChf, 2),
                    'cap_chf' => $spendingCap,
                    'percent_used' => round($percentUsed, 1),
                ], 402);
            }
        }

        return $next($request);
    }
}
