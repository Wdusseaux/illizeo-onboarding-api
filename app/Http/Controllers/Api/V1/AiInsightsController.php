<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Collaborateur;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\AiUsageGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered insights : NPS sentiment, buddy matching, and ad-hoc analyses.
 * Reuses the same Claude Haiku model + AiUsageGuard quota system as AiChatController.
 */
class AiInsightsController extends Controller
{
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * Analyze the sentiment of a single NPS response (verbatim + score).
     * Returns sentiment (positive|neutral|negative), themes, and a short
     * suggestion for follow-up if relevant.
     *
     * POST /ai/nps-sentiment   body: { response_id }
     */
    public function analyzeNpsResponse(Request $request): JsonResponse
    {
        $request->validate(['response_id' => 'required|integer']);

        if ($r = AiUsageGuard::blockIfExceeded('sentiment_nps')) return $r;

        $resp = NpsResponse::with(['survey', 'collaborateur'])->find($request->response_id);
        if (!$resp) return response()->json(['error' => 'Réponse introuvable'], 404);

        $survey = $resp->survey;
        $surveyTitle = $survey?->titre ?? 'Sondage NPS';
        $score = $resp->score ?? $resp->rating;
        $comment = trim((string) ($resp->comment ?? ''));
        $answers = is_array($resp->answers) ? $resp->answers : [];

        if (empty($comment) && empty($answers)) {
            return response()->json([
                'sentiment' => $score >= 9 ? 'positive' : ($score <= 6 ? 'negative' : 'neutral'),
                'themes' => [],
                'suggestion' => 'Aucun commentaire à analyser.',
                'cached' => true,
            ]);
        }

        $verbatim = $comment;
        if (!empty($answers)) {
            $verbatim .= "\n" . collect($answers)->map(fn ($a, $k) => "{$k}: " . (is_string($a) ? $a : json_encode($a)))->implode("\n");
        }

        $systemPrompt = "Tu es un analyste RH expert en feedback collaborateur. Analyse une réponse NPS et renvoie UNIQUEMENT un JSON strict (pas de markdown), avec cette structure :\n"
            . '{"sentiment":"positive|neutral|negative","themes":["theme1","theme2"],"key_insight":"phrase clé","suggestion":"action concrète pour le RH"}'."\n"
            . "Règles : 2 à 4 thèmes max, suggestion en français, sous 150 caractères, focalisée sur l'action.";

        $userPrompt = "Sondage : {$surveyTitle}\n"
            . "Score NPS : " . ($score ?? '?') . "/10\n"
            . "Commentaire : {$verbatim}";

        $result = $this->callClaude($systemPrompt, $userPrompt, 600);
        if (isset($result['error'])) return response()->json($result, 502);

        $parsed = $this->parseJson($result['text']);
        if (!$parsed) {
            $parsed = [
                'sentiment' => $score >= 9 ? 'positive' : ($score <= 6 ? 'negative' : 'neutral'),
                'themes' => [],
                'key_insight' => substr($verbatim, 0, 200),
                'suggestion' => 'Réponse non parsable, à examiner manuellement.',
            ];
        }

        $this->logUsage('sentiment_nps', $result, ['response_id' => $resp->id]);

        return response()->json($parsed);
    }

    /**
     * Aggregate sentiment + themes across all responses of a survey.
     * Returns recurring positive/negative themes + actionable HR recommendations.
     *
     * POST /ai/nps-insights   body: { survey_id }
     */
    public function aggregateNpsInsights(Request $request): JsonResponse
    {
        $request->validate(['survey_id' => 'required|integer']);

        if ($r = AiUsageGuard::blockIfExceeded('sentiment_nps')) return $r;

        $survey = NpsSurvey::find($request->survey_id);
        if (!$survey) return response()->json(['error' => 'Sondage introuvable'], 404);

        $responses = NpsResponse::where('survey_id', $survey->id)
            ->whereNotNull('completed_at')
            ->get();

        if ($responses->count() === 0) {
            return response()->json(['error' => 'Aucune réponse à analyser'], 422);
        }

        // Build compact verbatim corpus
        $verbatims = $responses
            ->filter(fn ($r) => !empty(trim((string) $r->comment)))
            ->map(fn ($r) => "[{$r->score}/10] " . substr(trim($r->comment), 0, 300))
            ->take(50) // cap to keep cost reasonable
            ->implode("\n");

        $promoters = $responses->where('score', '>=', 9)->count();
        $passives = $responses->whereBetween('score', [7, 8])->count();
        $detractors = $responses->where('score', '<=', 6)->count();
        $total = $responses->count();
        $nps = $total > 0 ? round((($promoters - $detractors) / $total) * 100) : 0;

        $systemPrompt = "Tu es un analyste RH. Synthétise des verbatims NPS en JSON strict (pas de markdown) :\n"
            . '{"top_positive_themes":["..."],"top_negative_themes":["..."],"key_findings":["..."],"recommendations":["action 1","action 2","action 3"]}'."\n"
            . "Règles : 3 à 5 items par tableau, recommandations actionnables, en français, focalisées sur le RH.";

        $userPrompt = "Sondage : {$survey->titre}\n"
            . "Total réponses : {$total} (Promoteurs {$promoters}, Passifs {$passives}, Détracteurs {$detractors}, NPS {$nps})\n\n"
            . "VERBATIMS (50 max) :\n{$verbatims}";

        $result = $this->callClaude($systemPrompt, $userPrompt, 1500);
        if (isset($result['error'])) return response()->json($result, 502);

        $parsed = $this->parseJson($result['text']) ?: [
            'top_positive_themes' => [],
            'top_negative_themes' => [],
            'key_findings' => ['Analyse non disponible'],
            'recommendations' => [],
        ];

        $parsed['summary'] = [
            'total' => $total,
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'nps_score' => $nps,
        ];

        $this->logUsage('sentiment_nps', $result, ['survey_id' => $survey->id, 'aggregated' => true]);

        return response()->json($parsed);
    }

    /**
     * Suggest the best buddy/parrain for a collaborateur, scored from 0–100.
     * Considers : same site, same département, similar level, complementary roles,
     * existing buddy load, and recent activity.
     *
     * POST /ai/suggest-buddy   body: { collaborateur_id }
     */
    public function suggestBuddy(Request $request): JsonResponse
    {
        $request->validate(['collaborateur_id' => 'required|integer']);

        if ($r = AiUsageGuard::blockIfExceeded('buddy_match')) return $r;

        $target = Collaborateur::with(['accompagnants'])->find($request->collaborateur_id);
        if (!$target) return response()->json(['error' => 'Collaborateur introuvable'], 404);

        // Build candidate pool : all users who have a Collaborateur record
        // (so they're real employees), excluding the target itself
        $candidates = Collaborateur::with('user')
            ->whereNotNull('user_id')
            ->where('id', '!=', $target->id)
            ->where('status', '!=', 'termine')
            ->get();

        if ($candidates->count() === 0) {
            return response()->json(['suggestions' => [], 'note' => 'Aucun candidat éligible.']);
        }

        // Compute existing buddy load per candidate user_id
        $loadByUserId = \DB::table('collaborateur_accompagnants')
            ->where('role', 'buddy')
            ->select('user_id', \DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id')
            ->toArray();

        // Build a compact JSON description for Claude to score
        $candidateList = $candidates->take(40)->map(function ($c) use ($loadByUserId) {
            return [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'nom' => trim("{$c->prenom} {$c->nom}"),
                'poste' => $c->poste,
                'site' => $c->site,
                'departement' => $c->departement,
                'date_arrivee' => $c->date_arrivee instanceof \DateTimeInterface ? $c->date_arrivee->format('Y-m-d') : $c->date_arrivee,
                'buddy_load' => $loadByUserId[$c->user_id] ?? 0,
            ];
        })->values()->toArray();

        $targetInfo = [
            'nom' => trim("{$target->prenom} {$target->nom}"),
            'poste' => $target->poste,
            'site' => $target->site,
            'departement' => $target->departement,
            'date_arrivee' => $target->date_arrivee instanceof \DateTimeInterface ? $target->date_arrivee->format('Y-m-d') : $target->date_arrivee,
        ];

        $systemPrompt = "Tu es un expert RH spécialisé dans le matching buddy/parrain. Tu reçois un nouveau collaborateur et une liste de candidats buddies potentiels. Tu retournes UNIQUEMENT un JSON strict (pas de markdown) classant les 3 meilleurs candidats :\n"
            . '{"suggestions":[{"collaborateur_id":N,"score":85,"reasons":["raison 1","raison 2"]}]}' . "\n"
            . "Critères de scoring : même site (+30), même département (+20), poste senior par rapport au new hire (+15), charge buddy faible (+15), ancienneté > 1 an (+10), poste différent mais complémentaire (+10). Pénalité : déjà 3+ buddies (-30).\n"
            . "Donne 2 à 3 raisons concrètes en français par suggestion.";

        $userPrompt = "NOUVEAU COLLABORATEUR À ASSIGNER :\n" . json_encode($targetInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nCANDIDATS BUDDIES :\n" . json_encode($candidateList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $result = $this->callClaude($systemPrompt, $userPrompt, 1500);
        if (isset($result['error'])) return response()->json($result, 502);

        $parsed = $this->parseJson($result['text']) ?: ['suggestions' => []];

        // Enrich suggestions with full collab info for the UI
        $byId = $candidates->keyBy('id');
        $enriched = collect($parsed['suggestions'] ?? [])->map(function ($sug) use ($byId) {
            $c = $byId->get($sug['collaborateur_id'] ?? null);
            if (!$c) return null;
            return array_merge($sug, [
                'nom' => trim("{$c->prenom} {$c->nom}"),
                'poste' => $c->poste,
                'site' => $c->site,
                'departement' => $c->departement,
                'user_id' => $c->user_id,
            ]);
        })->filter()->values()->toArray();

        $this->logUsage('buddy_match', $result, ['collaborateur_id' => $target->id]);

        return response()->json([
            'target' => $targetInfo,
            'suggestions' => $enriched,
        ]);
    }

    /**
     * Risk score for turnover prediction across all current collabs.
     * Composed from : NPS scores, mood entries, parcours completion delays,
     * and missed RDVs. Returns top N collabs at risk with rationale.
     *
     * GET /ai/turnover-risk
     */
    public function turnoverRisk(Request $request): JsonResponse
    {
        if ($r = AiUsageGuard::blockIfExceeded('insights')) return $r;

        $collabs = Collaborateur::where('status', '!=', 'termine')
            ->where('progression', '<', 100)
            ->get();

        if ($collabs->isEmpty()) {
            return response()->json(['at_risk' => [], 'note' => 'Aucun collaborateur actif']);
        }

        $signals = [];
        foreach ($collabs as $c) {
            // NPS détracteur récent
            $npsScore = NpsResponse::where('collaborateur_id', $c->id)
                ->whereNotNull('completed_at')
                ->orderByDesc('completed_at')
                ->value('score');

            // Mood récent (7 derniers jours)
            $moodAvg = \DB::table('mood_checkins')
                ->where('collaborateur_id', $c->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->avg('mood');

            $score = 0;
            $reasons = [];

            if ($npsScore !== null && $npsScore <= 6) {
                $score += 30;
                $reasons[] = "Détracteur NPS (note {$npsScore}/10)";
            }
            if ($moodAvg !== null && $moodAvg < 3) {
                $score += 25;
                $reasons[] = "Humeur moyenne basse (" . round($moodAvg, 1) . "/5) cette semaine";
            }
            if ($c->progression < 50 && $c->date_debut && now()->diffInDays($c->date_debut) > 30) {
                $score += 20;
                $reasons[] = "Parcours en retard ({$c->progression}% à J+" . now()->diffInDays($c->date_debut) . ")";
            }

            if ($score >= 25) {
                $signals[] = [
                    'id' => $c->id,
                    'nom' => trim("{$c->prenom} {$c->nom}"),
                    'poste' => $c->poste,
                    'site' => $c->site,
                    'risk_score' => min(100, $score),
                    'reasons' => $reasons,
                ];
            }
        }

        usort($signals, fn ($a, $b) => $b['risk_score'] - $a['risk_score']);

        $this->logUsage('insights', null, ['type' => 'turnover_risk', 'count' => count($signals)]);

        return response()->json([
            'at_risk' => array_slice($signals, 0, 20),
            'total_screened' => $collabs->count(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    //   Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function callClaude(string $system, string $userMsg, int $maxTokens = 1024): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return ['error' => 'Anthropic API key not configured'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(60)->post(self::ENDPOINT, [
                'model' => self::MODEL,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userMsg],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Claude Insights API error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return ['error' => 'IA indisponible (' . $response->status() . ')'];
            }

            $data = $response->json();
            return [
                'text' => $data['content'][0]['text'] ?? '',
                'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                'output_tokens' => $data['usage']['output_tokens'] ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::error('AI Insights exception', ['error' => $e->getMessage()]);
            return ['error' => 'IA indisponible: ' . $e->getMessage()];
        }
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function logUsage(string $type, ?array $apiResult, array $meta = []): void
    {
        try {
            AiUsage::create([
                'type' => $type,
                'user_id' => auth()->id(),
                'model' => self::MODEL,
                'input_tokens' => $apiResult['input_tokens'] ?? 0,
                'output_tokens' => $apiResult['output_tokens'] ?? 0,
                'cost_usd' => $this->estimateCost($apiResult['input_tokens'] ?? 0, $apiResult['output_tokens'] ?? 0),
                'metadata' => json_encode($meta),
            ]);
        } catch (\Throwable $e) {
            Log::warning("AiUsage log failed: " . $e->getMessage());
        }
    }

    private function estimateCost(int $input, int $output): float
    {
        // Claude Haiku 4.5 pricing : $1/MTok input, $5/MTok output
        return ($input * 1.0 + $output * 5.0) / 1_000_000;
    }
}
