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
        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:nps,satisfaction,custom',
            'parcours_id' => 'nullable|exists:parcours,id',
            'declencheur' => 'nullable|string|in:fin_parcours,fin_phase,manuel,date_specifique',
            'date_envoi' => 'nullable|date',
            'questions' => 'required|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.type' => 'required|string|in:nps,rating,text,choice',
            'questions.*.options' => 'nullable|array',
            'actif' => 'nullable|boolean',
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
        $validated = $request->validate([
            'titre' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:nps,satisfaction,custom',
            'parcours_id' => 'nullable|exists:parcours,id',
            'declencheur' => 'nullable|string|in:fin_parcours,fin_phase,manuel,date_specifique',
            'date_envoi' => 'nullable|date',
            'questions' => 'sometimes|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.type' => 'required|string|in:nps,rating,text,choice',
            'questions.*.options' => 'nullable|array',
            'actif' => 'nullable|boolean',
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
}
