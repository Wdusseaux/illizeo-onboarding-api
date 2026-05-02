<?php

namespace App\Console\Commands;

use App\Models\Collaborateur;
use App\Models\CollaborateurAction;
use App\Models\NpsResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily proactive insights job — scans each tenant for actionable patterns
 * (e.g. "Marie n'a pas signé son contrat depuis 5 jours") and pushes
 * UserNotifications of type 'ai_suggestion' to admins so they can act
 * directly from their notification center.
 *
 * Each pattern is checked once per day per (tenant, target) to avoid spam :
 * we use the `data->fingerprint` field on UserNotification.
 */
class ProactiveAiInsights extends Command
{
    protected $signature = 'ai:proactive-insights {--tenant= : Run for one tenant only} {--dry-run : Print only}';
    protected $description = 'Scan tenant data daily and push proactive AI suggestions to admins';

    public function handle(): int
    {
        $tenantFilter = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $tenants = $tenantFilter
            ? Tenant::where('id', $tenantFilter)->get()
            : Tenant::all();

        $this->info("Proactive insights for {$tenants->count()} tenant(s)" . ($dryRun ? ' [DRY-RUN]' : ''));

        $totalNotifs = 0;
        foreach ($tenants as $tenant) {
            try {
                $count = $this->processTenant($tenant, $dryRun);
                $totalNotifs += $count;
                if ($count > 0) {
                    $this->info("  ✓ {$tenant->id}: {$count} insight(s)");
                }
            } catch (\Throwable $e) {
                Log::error("Proactive insights failed for {$tenant->id}: " . $e->getMessage());
                $this->error("  ✗ {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Total insights pushed: {$totalNotifs}");
        return 0;
    }

    private function processTenant(Tenant $tenant, bool $dryRun): int
    {
        tenancy()->initialize($tenant);

        try {
            $count = 0;
            $adminIds = User::role(['super_admin', 'admin_rh'])->pluck('id')->toArray();
            if (empty($adminIds)) return 0;

            // ─── Pattern 1 : Action stuck in progress for 5+ days ──
            $stuckActions = CollaborateurAction::with(['collaborateur', 'action'])
                ->where('status', 'en_cours')
                ->whereNotNull('started_at')
                ->where('started_at', '<', now()->subDays(5))
                ->where('started_at', '>=', now()->subDays(15)) // not too old
                ->limit(20)
                ->get();

            foreach ($stuckActions as $ca) {
                if (!$ca->collaborateur || !$ca->action) continue;
                $days = (int) $ca->started_at->diffInDays(now());
                $fingerprint = "stuck_action_{$ca->id}_" . now()->format('Y-W');

                $count += $this->push(
                    $adminIds,
                    $fingerprint,
                    'ai_suggestion',
                    'Action en attente',
                    "{$ca->collaborateur->prenom} {$ca->collaborateur->nom} n'a pas terminé « {$ca->action->titre} » depuis {$days} jours.",
                    'clock',
                    '#F9A825',
                    [
                        'collaborateur_id' => $ca->collaborateur_id,
                        'action_id' => $ca->action_id,
                        'suggestion' => 'Envoyer une relance',
                        'days' => $days,
                    ],
                    $dryRun
                );
            }

            // ─── Pattern 2 : Onboarding parcours late (J+30, < 50%) ──
            $lateOnboardings = Collaborateur::where('status', '!=', 'termine')
                ->where('progression', '<', 50)
                ->whereNotNull('date_debut')
                ->where('date_debut', '<', now()->subDays(30))
                ->where('date_debut', '>=', now()->subDays(60))
                ->limit(10)
                ->get();

            foreach ($lateOnboardings as $c) {
                $days = (int) Carbon::parse($c->date_debut)->diffInDays(now());
                $fingerprint = "late_onboarding_{$c->id}_" . now()->format('Y-W');

                $count += $this->push(
                    $adminIds,
                    $fingerprint,
                    'ai_suggestion',
                    'Onboarding en retard',
                    "{$c->prenom} {$c->nom} est à J+{$days} avec seulement {$c->progression}% de progression. Voulez-vous l'auditer ?",
                    'alert-triangle',
                    '#E53935',
                    [
                        'collaborateur_id' => $c->id,
                        'suggestion' => 'Voir le profil',
                        'progression' => $c->progression,
                        'days_since_start' => $days,
                    ],
                    $dryRun
                );
            }

            // ─── Pattern 3 : Very low mood (< 2.5/5 over last 7 days) ──
            $lowMoodCollabs = DB::table('mood_checkins')
                ->select('collaborateur_id', DB::raw('AVG(mood) as avg_mood'), DB::raw('COUNT(*) as cnt'))
                ->where('created_at', '>=', now()->subDays(7))
                ->whereNotNull('collaborateur_id')
                ->groupBy('collaborateur_id')
                ->having('avg_mood', '<', 2.5)
                ->having('cnt', '>=', 2)
                ->get();

            foreach ($lowMoodCollabs as $row) {
                $c = Collaborateur::find($row->collaborateur_id);
                if (!$c) continue;
                $fingerprint = "low_mood_{$c->id}_" . now()->format('Y-W');

                $count += $this->push(
                    $adminIds,
                    $fingerprint,
                    'ai_suggestion',
                    'Humeur préoccupante',
                    "{$c->prenom} {$c->nom} a une humeur moyenne de " . round($row->avg_mood, 1) . "/5 cette semaine. Un check-in 1:1 pourrait être utile.",
                    'frown',
                    '#7B5EA7',
                    [
                        'collaborateur_id' => $c->id,
                        'suggestion' => 'Planifier un 1:1',
                        'mood_avg' => round($row->avg_mood, 1),
                    ],
                    $dryRun
                );
            }

            // ─── Pattern 4 : NPS detractor (score <= 6) ──
            $detractors = NpsResponse::with('collaborateur')
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', now()->subDays(3))
                ->where('score', '<=', 6)
                ->limit(10)
                ->get();

            foreach ($detractors as $resp) {
                if (!$resp->collaborateur) continue;
                $fingerprint = "nps_detractor_{$resp->id}";

                $count += $this->push(
                    $adminIds,
                    $fingerprint,
                    'ai_suggestion',
                    'Détracteur NPS',
                    "{$resp->collaborateur->prenom} {$resp->collaborateur->nom} a noté {$resp->score}/10. Un échange direct est recommandé.",
                    'thumbs-down',
                    '#C62828',
                    [
                        'collaborateur_id' => $resp->collaborateur_id,
                        'nps_response_id' => $resp->id,
                        'suggestion' => 'Voir la réponse',
                        'score' => (int) $resp->score,
                    ],
                    $dryRun
                );
            }

            return $count;
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Push a notification to multiple admins, dedupe via fingerprint.
     * Returns the number of new notifications created.
     */
    private function push(array $userIds, string $fingerprint, string $type, string $title, string $content, string $icon, string $color, array $data, bool $dryRun): int
    {
        if ($dryRun) {
            $this->line("  [DRY] {$fingerprint}: {$title} — {$content}");
            return count($userIds);
        }

        $created = 0;
        $data['fingerprint'] = $fingerprint;

        foreach ($userIds as $uid) {
            // Dedup : skip if a notif with same fingerprint already exists for this user
            $exists = DB::table('user_notifications')
                ->where('user_id', $uid)
                ->where('data', 'like', '%"fingerprint":"' . $fingerprint . '"%')
                ->exists();
            if ($exists) continue;

            try {
                NotificationService::send($uid, $type, $title, $content, $icon, $color, $data);
                $created++;
            } catch (\Throwable $e) {
                Log::warning("Push notif failed for user {$uid}: " . $e->getMessage());
            }
        }
        return $created;
    }
}
