<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Collaborateur;
use App\Models\CollaborateurAction;
use App\Models\CompanyBlock;
use App\Models\Integration;
use App\Models\QuizCompletion;
use App\Models\UserNotification;
use App\Services\SlackService;
use App\Services\TeamsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmployeeController extends Controller
{
    /**
     * Default milestones used when no admin-configured journey_milestones block exists.
     */
    private function defaultMilestones(string $categorie = 'onboarding'): array
    {
        $sets = [
            'onboarding' => [
                ['day' => 1, 'label' => 'Premier jour', 'badge_name' => 'Explorateur', 'badge_color' => '#9C27B0', 'icon' => 'rocket', 'description' => "Bienvenue ! Découverte des outils, équipe et premiers pas."],
                ['day' => 7, 'label' => 'Première semaine', 'badge_name' => 'Acclimatation', 'badge_color' => '#1A73E8', 'icon' => 'sparkles', 'description' => "Vous prenez vos marques. Check-in émotionnel et rituels d'équipe."],
                ['day' => 30, 'label' => 'Premier mois', 'badge_name' => 'Autonome', 'badge_color' => '#00897B', 'icon' => 'award', 'description' => "Vous êtes opérationnel. Premières contributions concrètes."],
                ['day' => 60, 'label' => 'Deux mois', 'badge_name' => 'Intégré(e)', 'badge_color' => '#F9A825', 'icon' => 'star', 'description' => "Bilan intermédiaire. Vous portez des projets en autonomie."],
                ['day' => 100, 'label' => '100 jours', 'badge_name' => 'Diplômé(e)', 'badge_color' => '#E91E8C', 'icon' => 'trophy', 'description' => "Onboarding terminé. Bilan final et projection long terme."],
            ],
            'offboarding' => [
                ['day' => 1, 'label' => 'Annonce', 'badge_name' => 'Transparence', 'badge_color' => '#1A73E8', 'icon' => 'mail', 'description' => "Communication du départ à l'équipe."],
                ['day' => 14, 'label' => 'Passation', 'badge_name' => 'Transmission', 'badge_color' => '#F9A825', 'icon' => 'arrow-right', 'description' => "Transfert des dossiers et responsabilités."],
                ['day' => 30, 'label' => 'Au revoir', 'badge_name' => 'Bonne route', 'badge_color' => '#9C27B0', 'icon' => 'heart', 'description' => "Dernier jour. Restitution matériel, debrief, ouverture."],
            ],
            'reboarding' => [
                ['day' => 1, 'label' => 'Retour', 'badge_name' => 'De retour', 'badge_color' => '#9C27B0', 'icon' => 'rocket', 'description' => "Bienvenue de retour. Mise à jour des changements."],
                ['day' => 7, 'label' => 'Reprise', 'badge_name' => 'Réadaptation', 'badge_color' => '#1A73E8', 'icon' => 'sparkles', 'description' => "Réacclimatation à l'équipe et aux outils."],
                ['day' => 30, 'label' => 'Plein régime', 'badge_name' => 'Réintégré(e)', 'badge_color' => '#00897B', 'icon' => 'award', 'description' => "Retour à plein régime. Bilan retour."],
            ],
            'crossboarding' => [
                ['day' => 1, 'label' => 'Nouveau poste', 'badge_name' => 'Mobilité', 'badge_color' => '#9C27B0', 'icon' => 'rocket', 'description' => "Bienvenue dans votre nouveau rôle."],
                ['day' => 14, 'label' => 'Adaptation', 'badge_name' => 'Polyvalent(e)', 'badge_color' => '#1A73E8', 'icon' => 'sparkles', 'description' => "Vous prenez vos marques dans le nouveau périmètre."],
                ['day' => 60, 'label' => 'Réussite', 'badge_name' => 'Évolution', 'badge_color' => '#E91E8C', 'icon' => 'trophy', 'description' => "Bilan positif. Vous êtes à l'aise dans vos nouvelles fonctions."],
            ],
        ];
        return $sets[$categorie] ?? $sets['onboarding'];
    }

    /**
     * Resolve the milestones list for a collaborateur.
     * Reads from companyBlocks (type=journey_milestones, filtered by parcours category) — falls back to defaults.
     */
    private function resolveMilestones(?string $categorie): array
    {
        $cat = $categorie ?: 'onboarding';
        // Look for an active journey_milestones block matching this category (or no category = applies to all)
        try {
            $block = CompanyBlock::where('type', 'journey_milestones')
                ->where('actif', true)
                ->get()
                ->filter(function ($b) use ($cat) {
                    $blockCat = $b->data['categorie'] ?? null;
                    return !$blockCat || $blockCat === $cat;
                })
                ->sortByDesc(fn ($b) => $b->data['categorie'] ?? null) // prefer category-specific over generic
                ->first();
            if ($block && !empty($block->data['milestones']) && is_array($block->data['milestones'])) {
                return $block->data['milestones'];
            }
        } catch (\Throwable $e) {
            Log::warning('resolveMilestones failed: ' . $e->getMessage());
        }
        return $this->defaultMilestones($cat);
    }

    /**
     * GET /me/journey
     * Returns the milestones list + the current dayJ for the authenticated employee.
     * Used by the frontend to render the chronological 100j view.
     */
    public function journey(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);

        $collab = Collaborateur::with('parcours.categorie')
            ->where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        if (!$collab || !$collab->date_debut) {
            return response()->json(['milestones' => [], 'day_j' => 0, 'categorie' => null]);
        }

        $cat = $collab->parcours?->categorie?->slug ?? $collab->parcours?->categorie ?? 'onboarding';
        if (is_object($cat) && method_exists($cat, '__toString')) $cat = (string) $cat;

        $startDate = Carbon::parse($collab->date_debut);
        $dayJ = max(1, $startDate->diffInDays(now(), false) + 1);

        $milestones = $this->resolveMilestones(is_string($cat) ? $cat : 'onboarding');

        return response()->json([
            'milestones' => $milestones,
            'day_j' => (int) $dayJ,
            'categorie' => is_string($cat) ? $cat : 'onboarding',
        ]);
    }

    /**
     * POST /me/check-milestones
     * Auto-awards badges for milestones the employee has reached but doesn't have yet.
     * Idempotent: existing badges are not re-awarded. Notifies via in-app + Slack + Teams.
     */
    public function checkMilestones(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);

        $collab = Collaborateur::with('parcours.categorie')
            ->where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        if (!$collab || !$collab->date_debut) {
            return response()->json(['awarded' => [], 'day_j' => 0]);
        }

        $cat = $collab->parcours?->categorie?->slug ?? $collab->parcours?->categorie ?? 'onboarding';
        if (!is_string($cat)) $cat = 'onboarding';

        $startDate = Carbon::parse($collab->date_debut);
        $dayJ = max(1, $startDate->diffInDays(now(), false) + 1);
        $milestones = $this->resolveMilestones($cat);

        // Reached milestones = those with day <= dayJ
        $reached = array_filter($milestones, fn ($m) => isset($m['day']) && (int) $m['day'] <= $dayJ);

        $awarded = [];
        $employeeName = trim("{$collab->prenom} {$collab->nom}");
        foreach ($reached as $m) {
            $badgeName = $m['badge_name'] ?? null;
            if (!$badgeName) continue;
            $exists = Badge::where('user_id', $user->id)->where('nom', $badgeName)->exists();
            if ($exists) continue;
            $badge = Badge::create([
                'user_id' => $user->id,
                'collaborateur_id' => $collab->id,
                'nom' => $badgeName,
                'description' => $m['description'] ?? '',
                'icon' => $m['icon'] ?? 'trophy',
                'color' => $m['badge_color'] ?? '#E91E8C',
                'earned_at' => now(),
            ]);
            $awarded[] = $badge;

            // In-app notification for the employee
            UserNotification::create([
                'user_id' => $user->id,
                'type' => 'badge_earned',
                'title' => "🏆 Nouveau badge : {$badgeName}",
                'content' => $m['description'] ?? "Vous avez débloqué le badge « {$badgeName} » !",
                'icon' => 'trophy',
                'color' => $m['badge_color'] ?? '#E91E8C',
                'data' => ['badge_id' => $badge->id, 'milestone_day' => $m['day'] ?? null],
            ]);

            // Notify manager/HR via in-app
            $managerUserId = $collab->manager?->user_id;
            $hrManagerUserId = $collab->hrManager?->user_id;
            $recipients = collect([$managerUserId, $hrManagerUserId])->unique()->filter();
            foreach ($recipients as $rid) {
                UserNotification::create([
                    'user_id' => $rid,
                    'type' => 'badge_earned',
                    'title' => "🏆 {$employeeName} a débloqué « {$badgeName} »",
                    'content' => "Jalon J+{$m['day']} franchi.",
                    'icon' => 'trophy',
                    'color' => $m['badge_color'] ?? '#E91E8C',
                    'data' => ['collaborateur_id' => $collab->id, 'badge_name' => $badgeName],
                ]);
            }

            // Optional: Slack + Teams
            $this->broadcastBadge($collab, $employeeName, $badgeName, (int) ($m['day'] ?? 0), $m['description'] ?? '', $m['badge_color'] ?? '#E91E8C');
        }

        return response()->json([
            'awarded' => $awarded,
            'day_j' => (int) $dayJ,
            'total_milestones' => count($milestones),
            'reached_milestones' => count($reached),
        ]);
    }

    private function broadcastBadge(Collaborateur $collab, string $employeeName, string $badgeName, int $day, string $description, string $color): void
    {
        $title = "{$employeeName} a débloqué « {$badgeName} »";
        $message = "Jalon J+{$day} franchi" . ($description ? " — {$description}" : '');
        $facts = ['Collaborateur' => $employeeName, 'Badge' => $badgeName, 'Jalon' => "J+{$day}"];
        try {
            $teams = Integration::where('provider', 'teams')->where('connecte', true)->first();
            if ($teams && !empty($teams->config['webhook_url'])) {
                TeamsService::fromIntegration($teams)->sendWebhookCard("🏆 {$title}", $message, $color, $facts);
            }
        } catch (\Throwable $e) { Log::warning('Teams badge notify failed: ' . $e->getMessage()); }
        try {
            $slack = Integration::where('provider', 'slack')->where('connecte', true)->first();
            if ($slack && !empty($slack->config['webhook_url'])) {
                SlackService::fromIntegration($slack)->sendBlocks($title, $message, $facts, null, null, ':trophy:');
            }
        } catch (\Throwable $e) { Log::warning('Slack badge notify failed: ' . $e->getMessage()); }
    }

    public function markExcited(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        // 24h cooldown enforced via cache. The Stancl tenancy CacheManager wraps
        // every Cache call with `tags()`, which throws on non-tagging stores
        // (file/database). Wrap in try/catch so the cooldown is best-effort and
        // a misconfigured cache store can never break the user-facing action.
        $cacheKey = "employee_excited:{$user->id}";
        try {
            if (Cache::has($cacheKey)) {
                $remaining = Cache::get($cacheKey);
                return response()->json([
                    'ok' => false,
                    'cooldown' => true,
                    'message' => 'Vous avez déjà partagé votre enthousiasme aujourd\'hui',
                    'until' => $remaining,
                ], 429);
            }
        } catch (\Throwable) {
            // Cache unavailable — proceed without cooldown enforcement.
        }

        $collab = Collaborateur::with(['manager:id,user_id,prenom,nom', 'hrManager:id,user_id,prenom,nom'])
            ->where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        $employeeName = $collab
            ? trim("{$collab->prenom} {$collab->nom}")
            : ($user->name ?: $user->email);

        $recipientUserIds = collect();
        if ($collab) {
            if ($collab->manager?->user_id) {
                $recipientUserIds->push($collab->manager->user_id);
            }
            if ($collab->hrManager?->user_id) {
                $recipientUserIds->push($collab->hrManager->user_id);
            }
        }
        // Fallback: when no manager / HR assigned, route the notification to tenant
        // admins so the message isn't silently dropped (the toast was misleading
        // employees into thinking someone had been notified).
        if ($recipientUserIds->isEmpty()) {
            // Use whereHas instead of Spatie's role() scope: the latter throws
            // RoleDoesNotExist when a role isn't seeded on the tenant DB.
            $adminIds = \App\Models\User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))->pluck('id');
            $recipientUserIds = $recipientUserIds->merge($adminIds);
        }
        $recipientUserIds = $recipientUserIds->unique()->filter()
            ->reject(fn ($uid) => $uid === $user->id) // never notify the sender
            ->values();

        $payload = [
            'type' => 'employee_excited',
            'title' => "✨ {$employeeName} a hâte de commencer !",
            'content' => "{$employeeName} vient de partager son enthousiasme avant son arrivée.",
            'icon' => 'party',
            'color' => '#E91E8C',
            'data' => [
                'collaborateur_id' => $collab?->id,
                'employee_name' => $employeeName,
                'date_debut' => $collab?->date_debut?->toDateString(),
            ],
        ];

        $created = 0;
        foreach ($recipientUserIds as $uid) {
            UserNotification::create(array_merge($payload, ['user_id' => $uid]));
            $created++;
        }

        $channels = $this->broadcastToChannels($collab, $employeeName);

        try {
            Cache::put($cacheKey, now()->addDay()->toIso8601String(), now()->addDay());
        } catch (\Throwable) {
            // Cache unavailable — cooldown not enforced. Notification still sent.
        }

        $parts = [];
        if ($created > 0) $parts[] = "{$created} personne" . ($created > 1 ? 's' : '');
        foreach ($channels as $ch) $parts[] = $ch;

        return response()->json([
            'ok' => true,
            'recipients' => $created,
            'channels' => $channels,
            'message' => count($parts) > 0
                ? 'Votre enthousiasme a été partagé à ' . implode(', ', $parts) . ' !'
                : 'Votre enthousiasme a été enregistré',
        ]);
    }

    /**
     * Push a notification to connected Teams + Slack integrations.
     * Returns the list of channels that were successfully notified.
     */
    private function broadcastToChannels(?Collaborateur $collab, string $employeeName): array
    {
        $facts = [];
        if ($collab?->poste) $facts['Poste'] = $collab->poste;
        if ($collab?->departement) $facts['Département'] = $collab->departement;
        if ($collab?->date_debut) $facts['Date d\'arrivée'] = \Carbon\Carbon::parse($collab->date_debut)->format('d/m/Y');
        $title = "{$employeeName} a hâte de commencer !";
        $message = "Un(e) futur(e) collaborateur(trice) vient de partager son enthousiasme avant son arrivée. Souhaitons-lui la bienvenue dès maintenant !";

        $notified = [];

        // Teams
        try {
            $teams = Integration::where('provider', 'teams')->where('connecte', true)->first();
            if ($teams && !empty($teams->config['webhook_url'])) {
                $service = TeamsService::fromIntegration($teams);
                if ($service->sendWebhookCard("✨ {$title}", $message, '#E91E8C', $facts)) {
                    $notified[] = 'Teams';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Teams notify failed for employee_excited: ' . $e->getMessage());
        }

        // Slack
        try {
            $slack = Integration::where('provider', 'slack')->where('connecte', true)->first();
            if ($slack && !empty($slack->config['webhook_url'])) {
                $service = SlackService::fromIntegration($slack);
                if ($service->sendBlocks($title, $message, $facts, null, null, ':sparkles:')) {
                    $notified[] = 'Slack';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Slack notify failed for employee_excited: ' . $e->getMessage());
        }

        return $notified;
    }

    /**
     * POST /me/quiz/complete
     * Persists a culture-quiz completion and the XP earned for the leaderboard.
     * Idempotent per (user, block): subsequent submissions update the row in place
     * (so the player can retry — only their best/most-recent score is kept).
     */
    public function submitQuiz(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);

        $data = $request->validate([
            'block_id' => 'nullable|integer',
            'correct' => 'required|integer|min:0',
            'total' => 'required|integer|min:1',
            'xp_per_correct' => 'nullable|integer|min:0',
            'answers' => 'nullable|array',
        ]);

        $collab = Collaborateur::where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        $xpPerCorrect = $data['xp_per_correct'] ?? 10;
        $xpEarned = $data['correct'] * $xpPerCorrect;

        $completion = QuizCompletion::updateOrCreate(
            ['user_id' => $user->id, 'block_id' => $data['block_id'] ?? null],
            [
                'collaborateur_id' => $collab?->id,
                'correct' => $data['correct'],
                'total' => $data['total'],
                'xp_earned' => $xpEarned,
                'answers' => $data['answers'] ?? null,
                'completed_at' => now(),
            ]
        );

        return response()->json([
            'ok' => true,
            'xp_earned' => $xpEarned,
            'completion' => $completion,
        ]);
    }

    /**
     * GET /me/leaderboard
     * Returns the cohort (peers in the same parcours, status != "termine") with
     * cumulative XP per person, sorted descending. The current user is flagged is_me.
     *
     * XP formula (deterministic from existing data — no separate XP wallet table):
     *   - Each completed CollaborateurAction: +50 XP
     *   - Each Badge earned: +100 XP
     *   - Sum of QuizCompletion.xp_earned
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);

        $me = Collaborateur::where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        if (!$me) {
            return response()->json(['cohort' => [], 'my_rank' => null, 'my_xp' => 0]);
        }

        // Cohort = same parcours_id, not finished. Always include self even if no parcours_id.
        $peers = Collaborateur::query()
            ->when($me->parcours_id, fn ($q) => $q->where('parcours_id', $me->parcours_id))
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'termine');
            })
            ->get();

        if (!$peers->contains('id', $me->id)) $peers->push($me);

        $userIds = $peers->pluck('user_id')->filter()->unique()->values();
        $collabIds = $peers->pluck('id');

        // Pre-aggregate XP from completed actions per collaborateur, using each
        // Action's configured `xp` value (defaults to 50 via the column default).
        $actionXpByCollab = CollaborateurAction::whereIn('collaborateur_id', $collabIds)
            ->where('status', 'termine')
            ->join('actions', 'actions.id', '=', 'collaborateur_actions.action_id')
            ->selectRaw('collaborateur_actions.collaborateur_id, SUM(actions.xp) as xp_sum')
            ->groupBy('collaborateur_actions.collaborateur_id')
            ->pluck('xp_sum', 'collaborateur_id');

        // Pre-aggregate badge counts per user
        $badgeCounts = Badge::whereIn('user_id', $userIds)
            ->selectRaw('user_id, COUNT(*) as n')
            ->groupBy('user_id')
            ->pluck('n', 'user_id');

        // Pre-aggregate quiz XP per user
        $quizXp = QuizCompletion::whereIn('user_id', $userIds)
            ->selectRaw('user_id, SUM(xp_earned) as xp')
            ->groupBy('user_id')
            ->pluck('xp', 'user_id');

        $palette = ['#E91E8C', '#1A73E8', '#9C27B0', '#00897B', '#F9A825', '#1A1A2E'];

        $rows = $peers->map(function ($c, $i) use ($actionXpByCollab, $badgeCounts, $quizXp, $me, $palette) {
            $actionXp = (int) ($actionXpByCollab[$c->id] ?? 0);
            $badgeXp = ((int) ($badgeCounts[$c->user_id] ?? 0)) * 100;
            $qXp = (int) ($quizXp[$c->user_id] ?? 0);
            $total = $actionXp + $badgeXp + $qXp;
            $first = $c->prenom ? mb_substr($c->prenom, 0, 1) : '';
            $last = $c->nom ? mb_substr($c->nom, 0, 1) : '';
            return [
                'id' => $c->id,
                'name' => trim("{$c->prenom} " . ($c->nom ? mb_substr($c->nom, 0, 1) . '.' : '')),
                'initials' => mb_strtoupper($first . $last),
                'color' => $palette[$i % count($palette)],
                'xp' => $total,
                'xp_breakdown' => [
                    'actions' => $actionXp,
                    'badges' => $badgeXp,
                    'quiz' => $qXp,
                ],
                'is_me' => $c->id === $me->id,
            ];
        })->sortByDesc('xp')->values();

        $myRow = $rows->firstWhere('is_me', true);
        $myRank = $rows->search(fn ($r) => $r['is_me']);

        return response()->json([
            'cohort' => $rows,
            'my_rank' => $myRank !== false ? $myRank + 1 : null,
            'my_xp' => $myRow['xp'] ?? 0,
            'my_breakdown' => $myRow['xp_breakdown'] ?? null,
        ]);
    }
}
