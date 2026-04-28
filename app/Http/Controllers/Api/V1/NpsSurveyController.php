<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\NpsSoumis;
use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NpsSurveyController extends Controller
{
    public function index(): JsonResponse
    {
        $surveys = NpsSurvey::with('parcours')
            ->withCount(['responses', 'responses as completed_responses_count' => function ($q) {
                $q->whereNotNull('completed_at');
            }])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($surveys);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeLegacyTrigger($request);
        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:nps,satisfaction,custom',
            'parcours_id' => 'nullable|exists:parcours,id',
            'declencheur' => 'nullable|string|in:fin_parcours,fin_phase,manuel,date_specifique,delai_relatif',
            'delai_jours' => 'nullable|integer|min:0|max:3650|required_if:declencheur,delai_relatif',
            'phase_id' => 'nullable|integer|exists:phases,id|required_if:declencheur,fin_phase',
            'date_envoi' => 'nullable|date|required_if:declencheur,date_specifique',
            'questions' => 'required|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.type' => 'required|string|in:nps,rating,text,choice',
            'questions.*.options' => 'nullable|array',
            'actif' => 'nullable|boolean',
        ], [
            'delai_jours.required_if' => 'Un délai en jours est requis pour le déclencheur "délai relatif".',
            'phase_id.required_if' => 'Une phase est requise pour le déclencheur "fin de phase".',
            'date_envoi.required_if' => 'Une date d\'envoi est requise pour le déclencheur "date spécifique".',
        ]);

        $survey = NpsSurvey::create($validated);

        return response()->json($survey->load('parcours'), 201);
    }

    public function show(NpsSurvey $npsSurvey): JsonResponse
    {
        return response()->json(
            $npsSurvey->load(['parcours', 'responses.collaborateur'])
        );
    }

    public function update(Request $request, NpsSurvey $npsSurvey): JsonResponse
    {
        $this->normalizeLegacyTrigger($request);
        $validated = $request->validate([
            'titre' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:nps,satisfaction,custom',
            'parcours_id' => 'nullable|exists:parcours,id',
            'declencheur' => 'nullable|string|in:fin_parcours,fin_phase,manuel,date_specifique,delai_relatif',
            'delai_jours' => 'nullable|integer|min:0|max:3650|required_if:declencheur,delai_relatif',
            'phase_id' => 'nullable|integer|exists:phases,id|required_if:declencheur,fin_phase',
            'date_envoi' => 'nullable|date|required_if:declencheur,date_specifique',
            'questions' => 'sometimes|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.type' => 'required|string|in:nps,rating,text,choice',
            'questions.*.options' => 'nullable|array',
            'actif' => 'nullable|boolean',
        ], [
            'delai_jours.required_if' => 'Un délai en jours est requis pour le déclencheur "délai relatif".',
            'phase_id.required_if' => 'Une phase est requise pour le déclencheur "fin de phase".',
            'date_envoi.required_if' => 'Une date d\'envoi est requise pour le déclencheur "date spécifique".',
        ]);

        $npsSurvey->update($validated);

        return response()->json($npsSurvey->load('parcours'));
    }

    public function destroy(NpsSurvey $npsSurvey): JsonResponse
    {
        $npsSurvey->delete();

        return response()->json(null, 204);
    }

    public function stats(): JsonResponse
    {
        $responses = NpsResponse::whereNotNull('completed_at')->get();

        // NPS calculation: promoters% - detractors%
        $withScore = $responses->whereNotNull('score');
        $total = $withScore->count();

        $promoters = $withScore->where('score', '>=', 9)->count();
        $passives = $withScore->whereBetween('score', [7, 8])->count();
        $detractors = $withScore->where('score', '<=', 6)->count();

        $npsScore = $total > 0
            ? round(($promoters - $detractors) / $total * 100, 1)
            : null;

        // Average satisfaction rating
        $avgRating = $responses->whereNotNull('rating')->avg('rating');

        // Response rate
        $totalResponses = NpsResponse::count();
        $completedResponses = NpsResponse::whereNotNull('completed_at')->count();
        $responseRate = $totalResponses > 0
            ? round($completedResponses / $totalResponses * 100, 1)
            : 0;

        // Score evolution by month (SQLite compatible)
        $driver = \DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite' ? "strftime('%Y-%m', completed_at)" : "DATE_FORMAT(completed_at, '%Y-%m')";
        $evolution = NpsResponse::whereNotNull('completed_at')
            ->whereNotNull('score')
            ->selectRaw("{$monthExpr} as mois")
            ->selectRaw('AVG(score) as avg_score')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();

        return response()->json([
            'nps_score' => $npsScore,
            'avg_rating' => $avgRating ? round($avgRating, 1) : 0,
            'response_rate' => $responseRate,
            'total_responses' => $totalResponses,
            'total_completed' => $completedResponses,
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'distribution' => [
                ['label' => 'Promoteurs (9-10)', 'count' => $promoters, 'color' => '#4CAF50'],
                ['label' => 'Passifs (7-8)', 'count' => $passives, 'color' => '#F9A825'],
                ['label' => 'Détracteurs (0-6)', 'count' => $detractors, 'color' => '#E53935'],
            ],
            'evolution' => $evolution,
        ]);
    }

    public function sendToCollaborateur(Request $request, NpsSurvey $npsSurvey): JsonResponse
    {
        $validated = $request->validate([
            'collaborateur_id' => 'required|exists:collaborateurs,id',
        ]);

        $response = NpsResponse::create([
            'survey_id' => $npsSurvey->id,
            'collaborateur_id' => $validated['collaborateur_id'],
            'user_id' => auth()->id(),
        ]);

        return response()->json($response, 201);
    }

    public function sendToAll(NpsSurvey $npsSurvey): JsonResponse
    {
        $collaborateurs = Collaborateur::where('status', 'actif')->get();

        $created = 0;
        foreach ($collaborateurs as $collaborateur) {
            // Avoid duplicate pending responses for same survey
            $exists = NpsResponse::where('survey_id', $npsSurvey->id)
                ->where('collaborateur_id', $collaborateur->id)
                ->whereNull('completed_at')
                ->exists();

            if (!$exists) {
                NpsResponse::create([
                    'survey_id' => $npsSurvey->id,
                    'collaborateur_id' => $collaborateur->id,
                    'user_id' => auth()->id(),
                ]);
                $created++;
            }
        }

        return response()->json([
            'message' => "$created enquête(s) envoyée(s).",
            'created' => $created,
        ]);
    }

    public function getByToken(string $token): JsonResponse
    {
        $response = NpsResponse::where('token', $token)
            ->with(['survey', 'collaborateur'])
            ->firstOrFail();

        if ($response->completed_at) {
            return response()->json(['message' => 'Cette enquête a déjà été complétée.'], 410);
        }

        return response()->json($response);
    }

    public function respond(Request $request, string $token): JsonResponse
    {
        $npsResponse = NpsResponse::where('token', $token)->firstOrFail();

        if ($npsResponse->completed_at) {
            return response()->json(['message' => 'Cette enquête a déjà été complétée.'], 410);
        }

        $validated = $request->validate([
            'score' => 'nullable|integer|min:0|max:10',
            'rating' => 'nullable|numeric|min:1|max:5',
            'answers' => 'nullable|array',
            'comment' => 'nullable|string',
        ]);

        $npsResponse->update(array_merge($validated, [
            'completed_at' => Carbon::now(),
        ]));

        // Fire event for workflow engine
        $survey = $npsResponse->survey()->with('parcours')->first();
        $parcoursName = $survey->parcours?->nom ?? $survey->titre;

        if ($npsResponse->score !== null) {
            NpsSoumis::dispatch(
                $npsResponse->collaborateur_id,
                $npsResponse->score,
                $parcoursName,
            );
        }

        // Auto-award badge for NPS completion
        if ($npsResponse->user_id) {
            \App\Services\BadgeAutoAwardService::checkAndAward($npsResponse->user_id, 'nps_complete');
        }

        return response()->json(['message' => 'Merci pour votre réponse !']);
    }

    /**
     * Get pending and completed NPS surveys for the authenticated employee.
     */
    public function myPendingSurveys(Request $request): JsonResponse
    {
        $user = $request->user();
        $collab = Collaborateur::where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        if (!$collab) {
            // Not a collaborateur — return active surveys without tokens
            $surveys = NpsSurvey::where('actif', true)->get();
            return response()->json($surveys->map(fn ($s) => [
                'survey' => $s,
                'token' => null,
                'completed' => false,
                'completed_at' => null,
            ]));
        }

        // Get all responses for this collaborateur (pending + completed)
        $responses = NpsResponse::where('collaborateur_id', $collab->id)
            ->with('survey')
            ->get();

        // Also include active surveys without a response yet — but only those
        // whose `declencheur` condition is currently satisfied for this collab.
        // (Previously every active survey was auto-shown regardless of trigger.)
        $respondedSurveyIds = $responses->pluck('survey_id')->toArray();
        $unrepondedSurveys = NpsSurvey::where('actif', true)
            ->whereNotIn('id', $respondedSurveyIds)
            ->get()
            ->filter(fn ($s) => $this->shouldShowSurvey($s, $collab));

        $result = [];

        foreach ($responses as $resp) {
            if (!$resp->survey || !$resp->survey->actif) continue;
            $result[] = [
                'survey' => $resp->survey,
                'token' => $resp->token,
                'completed' => $resp->completed_at !== null,
                'completed_at' => $resp->completed_at,
                'score' => $resp->score,
                'rating' => $resp->rating,
            ];
        }

        foreach ($unrepondedSurveys as $survey) {
            // Auto-create a response with token for this employee
            $resp = NpsResponse::create([
                'survey_id' => $survey->id,
                'collaborateur_id' => $collab->id,
                'user_id' => $user->id,
            ]);
            $result[] = [
                'survey' => $survey,
                'token' => $resp->token,
                'completed' => false,
                'completed_at' => null,
            ];
        }

        return response()->json($result);
    }

    /**
     * Translate the legacy frontend triggers `j_plus_7|30|60|90` into the new
     * `delai_relatif` + `delai_jours` shape so existing UI keeps working while we
     * migrate the admin form.
     */
    private function normalizeLegacyTrigger(Request $request): void
    {
        $declencheur = $request->input('declencheur');
        if (is_string($declencheur) && preg_match('/^j_plus_(\d+)$/', $declencheur, $m)) {
            $request->merge([
                'declencheur' => 'delai_relatif',
                'delai_jours' => (int) $m[1],
            ]);
        }
    }

    /**
     * Decide whether a survey should currently be presented to a given collaborateur,
     * based on its declencheur (trigger). Surveys gated to "manuel" or "fin_phase"
     * are never auto-shown — they require an explicit admin action (manuel) or per-
     * phase completion tracking (fin_phase, not yet implemented).
     */
    private function shouldShowSurvey(NpsSurvey $survey, Collaborateur $collab): bool
    {
        // Per-parcours scoping: if survey targets a specific parcours, the collab
        // must be on that parcours.
        if ($survey->parcours_id && (int) $survey->parcours_id !== (int) $collab->parcours_id) {
            return false;
        }

        return match ($survey->declencheur) {
            'manuel' => false,
            'fin_parcours' => ($collab->status === 'termine') || ((int) ($collab->progression ?? 0) >= 100),
            'fin_phase' => $this->phaseCompletedFor($survey, $collab),
            'date_specifique' => $survey->date_envoi !== null && now()->greaterThanOrEqualTo($survey->date_envoi),
            'delai_relatif' => $this->relativeDelayElapsed($survey, $collab),
            default => true,
        };
    }

    /**
     * For declencheur=delai_relatif: true once `delai_jours` days have elapsed
     * since the collab's date_debut. Returns false if either field is missing.
     */
    private function relativeDelayElapsed(NpsSurvey $survey, Collaborateur $collab): bool
    {
        if (!$collab->date_debut || $survey->delai_jours === null) return false;
        $start = $collab->date_debut instanceof Carbon
            ? $collab->date_debut
            : Carbon::parse($collab->date_debut);
        return now()->greaterThanOrEqualTo($start->copy()->addDays((int) $survey->delai_jours));
    }

    /**
     * For declencheur=fin_phase: true once every Action of the targeted phase that
     * is assigned to this collab has status='termine'. Returns false if phase_id
     * is missing or the collab has no assigned actions in that phase yet.
     */
    private function phaseCompletedFor(NpsSurvey $survey, Collaborateur $collab): bool
    {
        if (!$survey->phase_id) return false;
        $assigned = \App\Models\CollaborateurAction::where('collaborateur_id', $collab->id)
            ->whereHas('action', fn ($q) => $q->where('phase_id', $survey->phase_id))
            ->get();
        if ($assigned->isEmpty()) return false;
        return $assigned->every(fn ($a) => $a->status === 'termine');
    }
}
