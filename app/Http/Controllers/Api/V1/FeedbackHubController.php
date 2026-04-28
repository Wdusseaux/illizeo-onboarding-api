<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuddyRating;
use App\Models\Collaborateur;
use App\Models\ConfidentialAlert;
use App\Models\FeedbackSuggestion;
use App\Models\MoodCheckin;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feedback hub: 4 distinct ascending channels (employee → RH/admin).
 *
 * - mood: regular pulse (1-5 + comment)
 * - suggestion: free-form suggestion / bug / improvement, optionally anonymous
 * - buddy_rating: rate buddy or manager (1-5 + comment), employee-private
 * - confidential_alert: RPS / harcèlement / discrimination, restricted RH read,
 *   may be fully anonymous (no user_id stored)
 *
 * All POSTs notify tenant admins in-app so feedback isn't dropped silently.
 */
class FeedbackHubController extends Controller
{
    private function collabOf(Request $request): ?Collaborateur
    {
        $user = $request->user();
        if (!$user) return null;
        return Collaborateur::where('user_id', $user->id)->orWhere('email', $user->email)->first();
    }

    private function notifyAdmins(string $title, string $content, string $color = '#1A73E8', string $icon = 'message'): void
    {
        // Use whereHas instead of Spatie's `role()` scope: the latter throws
        // RoleDoesNotExist when the role isn't seeded on a tenant DB, and a
        // feedback submission must never fail because notifications can't reach admins.
        $adminIds = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))->pluck('id');
        foreach ($adminIds as $uid) {
            UserNotification::create([
                'user_id' => $uid,
                'type' => 'feedback_hub',
                'title' => $title,
                'content' => $content,
                'icon' => $icon,
                'color' => $color,
                'data' => [],
            ]);
        }
    }

    // ─── MOOD CHECK-IN ────────────────────────────────────────
    public function storeMood(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);
        $data = $request->validate([
            'mood' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);
        $collab = $this->collabOf($request);
        $entry = MoodCheckin::create([
            'user_id' => $user->id,
            'collaborateur_id' => $collab?->id,
            'mood' => $data['mood'],
            'comment' => $data['comment'] ?? null,
        ]);
        // Only escalate to admins if mood is bad (1 or 2) — otherwise it's just personal tracking
        if ($data['mood'] <= 2) {
            $name = $collab ? trim("{$collab->prenom} {$collab->nom}") : ($user->name ?? $user->email);
            $emoji = $data['mood'] === 1 ? '😞' : '😟';
            $this->notifyAdmins(
                "{$emoji} Mood faible — {$name}",
                "Note {$data['mood']}/5" . (($data['comment'] ?? null) ? " · « " . mb_substr($data['comment'], 0, 120) . " »" : ''),
                '#E53935',
                'alert'
            );
        }
        return response()->json(['ok' => true, 'entry' => $entry]);
    }

    public function listMyMoods(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);
        $entries = MoodCheckin::where('user_id', $user->id)->orderByDesc('created_at')->limit(30)->get();
        return response()->json($entries);
    }

    // ─── SUGGESTIONS / BUGS ───────────────────────────────────
    public function storeSuggestion(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);
        $data = $request->validate([
            'category' => 'nullable|string|in:suggestion,bug,improvement,other',
            'content' => 'required|string|max:5000',
            'anonymous' => 'nullable|boolean',
        ]);
        $collab = $this->collabOf($request);
        $isAnon = (bool) ($data['anonymous'] ?? false);
        $entry = FeedbackSuggestion::create([
            'user_id' => $isAnon ? null : $user->id,
            'collaborateur_id' => $isAnon ? null : $collab?->id,
            'category' => $data['category'] ?? 'suggestion',
            'content' => $data['content'],
            'anonymous' => $isAnon,
            'status' => 'open',
        ]);
        $name = $isAnon ? 'Anonyme' : ($collab ? trim("{$collab->prenom} {$collab->nom}") : ($user->name ?? $user->email));
        $catLabel = match ($entry->category) { 'bug' => '🐛 Bug', 'improvement' => '✨ Amélioration', 'other' => '💬 Autre', default => '💡 Suggestion' };
        $this->notifyAdmins(
            "{$catLabel} — {$name}",
            mb_substr($data['content'], 0, 160),
            '#1A73E8',
            'message'
        );
        return response()->json(['ok' => true, 'entry' => $entry]);
    }

    // ─── BUDDY / MANAGER RATING ───────────────────────────────
    public function storeBuddyRating(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);
        $data = $request->validate([
            'target_type' => 'required|string|in:buddy,manager',
            'target_user_id' => 'nullable|integer',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);
        $collab = $this->collabOf($request);
        $entry = BuddyRating::create([
            'user_id' => $user->id,
            'collaborateur_id' => $collab?->id,
            'target_type' => $data['target_type'],
            'target_user_id' => $data['target_user_id'] ?? null,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);
        // Only notify admins if rating is poor (1 or 2)
        if ($data['rating'] <= 2) {
            $name = $collab ? trim("{$collab->prenom} {$collab->nom}") : ($user->name ?? $user->email);
            $targetLabel = $data['target_type'] === 'buddy' ? 'buddy' : 'manager';
            $this->notifyAdmins(
                "⚠️ Note {$targetLabel} faible — {$name}",
                "Note {$data['rating']}/5" . (($data['comment'] ?? null) ? " · « " . mb_substr($data['comment'], 0, 120) . " »" : ''),
                '#F9A825',
                'alert'
            );
        }
        return response()->json(['ok' => true, 'entry' => $entry]);
    }

    public function listMyBuddyRatings(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);
        return response()->json(BuddyRating::where('user_id', $user->id)->orderByDesc('created_at')->get());
    }

    // ─── ADMIN VIEWS ──────────────────────────────────────────
    private function ensureAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'admin'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        return null;
    }

    public function adminListMoods(Request $request): JsonResponse
    {
        if ($r = $this->ensureAdmin($request)) return $r;
        $entries = MoodCheckin::with('collaborateur:id,prenom,nom,email')
            ->orderByDesc('created_at')->limit(200)->get();

        // Stats: distribution + 30-day rolling average
        $cutoff = now()->subDays(30);
        $recent = $entries->where('created_at', '>=', $cutoff);
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($recent as $e) $distribution[$e->mood] = ($distribution[$e->mood] ?? 0) + 1;
        $avg = $recent->count() > 0 ? round($recent->avg('mood'), 2) : null;

        return response()->json([
            'entries' => $entries,
            'stats' => [
                'avg_30d' => $avg,
                'count_30d' => $recent->count(),
                'distribution_30d' => $distribution,
                'low_mood_count_30d' => $recent->where('mood', '<=', 2)->count(),
            ],
        ]);
    }

    public function adminListSuggestions(Request $request): JsonResponse
    {
        if ($r = $this->ensureAdmin($request)) return $r;
        $status = $request->query('status'); // open | reviewing | done | dismissed | null=all
        $q = FeedbackSuggestion::with('collaborateur:id,prenom,nom,email')->orderByDesc('created_at');
        if ($status) $q->where('status', $status);
        return response()->json($q->limit(200)->get());
    }

    public function adminUpdateSuggestion(Request $request, int $id): JsonResponse
    {
        if ($r = $this->ensureAdmin($request)) return $r;
        $data = $request->validate([
            'status' => 'required|string|in:open,reviewing,done,dismissed',
        ]);
        $s = FeedbackSuggestion::findOrFail($id);
        $s->update(['status' => $data['status']]);
        return response()->json(['ok' => true, 'entry' => $s]);
    }

    /**
     * GET /admin/feedback/excited
     * Lists all "J'ai hâte" clicks (UserNotification with type=employee_excited).
     * We deduplicate by collaborateur_id keeping only the most recent click per
     * person, since the front-end shows it as "X a hâte" once per arrival.
     */
    public function adminListExcited(Request $request): JsonResponse
    {
        if ($r = $this->ensureAdmin($request)) return $r;
        $rows = UserNotification::where('type', 'employee_excited')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
        // Dedupe by collaborateur_id (data->collaborateur_id) — keep most recent
        $seen = [];
        $entries = [];
        foreach ($rows as $r) {
            $cid = $r->data['collaborateur_id'] ?? null;
            $key = $cid ?: ('uid:' . $r->user_id);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $entries[] = [
                'id' => $r->id,
                'collaborateur_id' => $cid,
                'employee_name' => $r->data['employee_name'] ?? 'Collaborateur',
                'date_debut' => $r->data['date_debut'] ?? null,
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        }
        return response()->json([
            'entries' => $entries,
            'total' => count($entries),
        ]);
    }

    public function adminListBuddyRatings(Request $request): JsonResponse
    {
        if ($r = $this->ensureAdmin($request)) return $r;
        $entries = BuddyRating::with('collaborateur:id,prenom,nom,email')
            ->orderByDesc('created_at')->limit(200)->get();
        $avgBuddy = $entries->where('target_type', 'buddy')->avg('rating');
        $avgManager = $entries->where('target_type', 'manager')->avg('rating');
        return response()->json([
            'entries' => $entries,
            'stats' => [
                'avg_buddy' => $avgBuddy ? round($avgBuddy, 2) : null,
                'avg_manager' => $avgManager ? round($avgManager, 2) : null,
                'low_count' => $entries->where('rating', '<=', 2)->count(),
            ],
        ]);
    }

    // ─── CONFIDENTIAL RH ALERT ────────────────────────────────
    public function storeConfidentialAlert(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Non authentifié'], 401);
        $data = $request->validate([
            'category' => 'required|string|in:rps,harcelement,discrimination,autre',
            'content' => 'required|string|max:5000',
            'anonymous' => 'nullable|boolean',
        ]);
        $isAnon = (bool) ($data['anonymous'] ?? false);
        $entry = ConfidentialAlert::create([
            'user_id' => $isAnon ? null : $user->id,
            'anonymous' => $isAnon,
            'category' => $data['category'],
            'content' => $data['content'],
            'status' => 'new',
        ]);
        // Always notify admins (RH); body is intentionally short — full content is in the admin tool.
        $catLabel = match ($entry->category) {
            'rps' => '🚨 RPS',
            'harcelement' => '🚨 Harcèlement',
            'discrimination' => '🚨 Discrimination',
            default => '🚨 Alerte RH',
        };
        $this->notifyAdmins(
            "{$catLabel} — alerte confidentielle reçue",
            $isAnon ? "Alerte anonyme déposée. Consultez le tableau RH dans l'admin." : "Une alerte a été déposée. Consultez le tableau RH.",
            '#E53935',
            'alert'
        );
        return response()->json(['ok' => true, 'id' => $entry->id]);
    }
}
