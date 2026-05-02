<?php

namespace App\Console\Commands;

use App\Mail\WeeklyAiSummaryMail;
use App\Models\AiUsage;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WeeklyAiSummary extends Command
{
    protected $signature = 'ai:weekly-summary {--tenant= : Run for a single tenant ID} {--dry-run : Skip email send}';
    protected $description = 'Generate AI-powered weekly executive summary and email it to tenant admins';

    private const MODEL = 'claude-haiku-4-5-20251001';
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public function handle(): int
    {
        $tenantFilter = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $tenants = $tenantFilter
            ? Tenant::where('id', $tenantFilter)->get()
            : Tenant::all();

        $this->info("Processing weekly summary for {$tenants->count()} tenant(s)" . ($dryRun ? ' [DRY-RUN]' : ''));

        $sent = 0;
        foreach ($tenants as $tenant) {
            try {
                $this->processTenant($tenant, $dryRun);
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Weekly summary failed for tenant {$tenant->id}: " . $e->getMessage());
                $this->error("  ✗ {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info("Done. {$sent}/{$tenants->count()} processed.");
        return 0;
    }

    private function processTenant(Tenant $tenant, bool $dryRun): void
    {
        // Skip tenants without active subscription (trial doesn't count for the AI summary)
        $hasActiveSub = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('addon_type', '!=', 'ai'))
            ->exists();

        if (!$hasActiveSub) {
            $this->line("  - {$tenant->id}: skip (no active subscription)");
            return;
        }

        // Skip tenants on IA Starter — weekly summary requires Business+
        $aiPlan = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn ($q) => $q->where('addon_type', 'ai'))
            ->with('plan')
            ->first();

        if (!$aiPlan) {
            $this->line("  - {$tenant->id}: skip (no AI plan)");
            return;
        }
        $slug = $aiPlan->plan->slug ?? '';
        if (str_contains($slug, 'starter') || str_contains($slug, 'ia_starter')) {
            $this->line("  - {$tenant->id}: skip (IA Starter — weekly summary is Business+)");
            return;
        }

        // Switch to tenant context
        tenancy()->initialize($tenant);

        try {
            $weekStart = now()->subDays(7)->startOfDay();
            $weekEnd = now()->endOfDay();

            // Compute KPIs
            $newCollabs = \App\Models\Collaborateur::where('created_at', '>=', $weekStart)->count();
            $completedActions = \App\Models\CollaborateurAction::where('status', 'termine')
                ->whereBetween('completed_at', [$weekStart, $weekEnd])
                ->count();
            // "Late" = not yet completed and started more than 7 days ago
            $lateActions = \App\Models\CollaborateurAction::where('status', '!=', 'termine')
                ->whereNotNull('started_at')
                ->where('started_at', '<', now()->subDays(7))
                ->count();
            $avgProgression = (float) \App\Models\Collaborateur::where('status', '!=', 'termine')
                ->avg('progression') ?: 0;

            // NPS — last completed survey
            $latestNps = \App\Models\NpsResponse::whereNotNull('completed_at')
                ->whereBetween('completed_at', [$weekStart, $weekEnd])
                ->get();
            $npsScore = null;
            if ($latestNps->count() > 0) {
                $promoters = $latestNps->where('score', '>=', 9)->count();
                $detractors = $latestNps->where('score', '<=', 6)->count();
                $npsScore = round((($promoters - $detractors) / $latestNps->count()) * 100);
            }

            // Mood
            $moodAvg = \DB::table('mood_checkins')
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->avg('mood');

            $kpis = [
                'new_collabs' => $newCollabs,
                'completed_actions' => $completedActions,
                'late_actions' => $lateActions,
                'avg_progression' => $avgProgression,
                'nps_score' => $npsScore,
                'mood_avg' => $moodAvg,
            ];

            // Generate AI narrative + recommendations
            $aiResult = $this->generateNarrative($tenant, $kpis);
            $narrative = $aiResult['narrative'];
            $recommendations = $aiResult['recommendations'];

            // Send to all admins of the tenant
            $admins = \App\Models\User::role(['super_admin', 'admin_rh'])->get();

            $appUrl = config('app.frontend_url') ?: env('FRONTEND_URL', 'https://onboarding.illizeo.com');
            $tenantUrl = "{$appUrl}/{$tenant->id}";
            $weekLabel = $weekStart->locale('fr')->isoFormat('D MMM') . ' au ' . $weekEnd->locale('fr')->isoFormat('D MMM YYYY');

            foreach ($admins as $admin) {
                if (!$admin->email) continue;

                if ($dryRun) {
                    $this->line("  [DRY] would email {$admin->email} ({$tenant->id})");
                    continue;
                }

                Mail::to($admin->email)->send(new WeeklyAiSummaryMail(
                    tenantName: $tenant->nom ?? $tenant->id,
                    adminName: $admin->name ?? 'Admin',
                    weekLabel: $weekLabel,
                    kpis: $kpis,
                    aiNarrative: $narrative,
                    recommendations: $recommendations,
                    appUrl: $tenantUrl,
                ));
            }

            $this->info("  ✓ {$tenant->id}: " . count($admins) . " admins notified");
        } finally {
            tenancy()->end();
        }
    }

    private function generateNarrative(Tenant $tenant, array $kpis): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return [
                'narrative' => 'Configuration IA manquante — résumé non généré.',
                'recommendations' => [],
            ];
        }

        $kpiJson = json_encode($kpis, JSON_UNESCAPED_UNICODE);

        $systemPrompt = "Tu es un analyste RH senior. Tu reçois les KPIs hebdomadaires d'une entreprise et tu produis un résumé exécutif de 3-4 phrases (factuel, sans buzzwords) + 3 recommandations actionnables. Renvoie UNIQUEMENT un JSON strict :\n"
            . '{"narrative":"3-4 phrases en français","recommendations":["action 1","action 2","action 3"]}'."\n"
            . "Règles : narrative concise (max 400 caractères), recommandations spécifiques (pas génériques), basées sur les chiffres.";

        $userPrompt = "Entreprise : {$tenant->nom}\nKPIs de la semaine : {$kpiJson}";

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(60)->post(self::ENDPOINT, [
                'model' => self::MODEL,
                'max_tokens' => 800,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userPrompt]],
            ]);

            if (!$response->successful()) {
                return [
                    'narrative' => 'Synthèse IA temporairement indisponible. Voici vos chiffres bruts ci-dessus.',
                    'recommendations' => [],
                ];
            }

            $data = $response->json();
            $text = trim($data['content'][0]['text'] ?? '');

            // Strip markdown fences
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
                $text = preg_replace('/\n?```\s*$/', '', $text);
            }

            $parsed = json_decode($text, true);

            // Track usage
            try {
                AiUsage::create([
                    'type' => 'weekly_summary',
                    'user_id' => null,
                    'model' => self::MODEL,
                    'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                    'output_tokens' => $data['usage']['output_tokens'] ?? 0,
                    'cost_usd' => (($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0) * 5) / 1_000_000,
                    'metadata' => json_encode(['tenant_id' => $tenant->id]),
                ]);
            } catch (\Throwable $e) {
                // ignore
            }

            return [
                'narrative' => $parsed['narrative'] ?? 'Synthèse non disponible.',
                'recommendations' => $parsed['recommendations'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error("Narrative generation failed: " . $e->getMessage());
            return [
                'narrative' => 'Synthèse IA temporairement indisponible.',
                'recommendations' => [],
            ];
        }
    }
}
