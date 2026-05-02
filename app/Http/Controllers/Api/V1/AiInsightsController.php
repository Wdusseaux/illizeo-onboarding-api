<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Collaborateur;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\Subscription;
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

        if ($r = $this->blockIfStarter('Analyse agrégée NPS')) return $r;
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
     *
     * 3-level analysis:
     *  - Level 1 (rules)    : score from NPS, mood avg, progression delay (fast)
     *  - Level 2 (verbatims): Claude reads NPS/mood comments to enrich the narrative
     *  - Level 3 (temporal) : Claude detects 4-week trends (mood declining, etc.)
     *
     * GET /ai/turnover-risk?enrich=1  (default 1; set 0 to disable Claude)
     */
    public function turnoverRisk(Request $request): JsonResponse
    {
        if ($r = $this->blockIfStarter('Analyse de risque turnover')) return $r;
        if ($r = AiUsageGuard::blockIfExceeded('insights')) return $r;

        $enrich = $request->query('enrich', '1') === '1';

        $collabs = Collaborateur::where('status', '!=', 'termine')
            ->where('progression', '<', 100)
            ->get();

        if ($collabs->isEmpty()) {
            return response()->json(['at_risk' => [], 'total_screened' => 0, 'enriched' => false]);
        }

        $collabIds = $collabs->pluck('id')->all();

        // ── Pre-compute temporal data in batch (avoid N+1 queries) ──
        // Mood weekly average over 4 weeks
        $moodByCollabWeek = \DB::table('mood_checkins')
            ->select('collaborateur_id', \DB::raw('FLOOR(DATEDIFF(NOW(), created_at) / 7) as week_offset'), \DB::raw('AVG(mood) as avg_mood'), \DB::raw('COUNT(*) as cnt'))
            ->whereIn('collaborateur_id', $collabIds)
            ->where('created_at', '>=', now()->subDays(28))
            ->groupBy('collaborateur_id', 'week_offset')
            ->get()
            ->groupBy('collaborateur_id');

        // Recent mood comments
        $moodCommentsByCollab = \DB::table('mood_checkins')
            ->whereIn('collaborateur_id', $collabIds)
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->get(['collaborateur_id', 'mood', 'comment', 'created_at'])
            ->groupBy('collaborateur_id');

        // NPS history (last 3 responses + verbatim)
        $npsByCollab = NpsResponse::whereIn('collaborateur_id', $collabIds)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->get(['id', 'collaborateur_id', 'score', 'comment', 'completed_at'])
            ->groupBy('collaborateur_id');

        // Action completion velocity (actions completed last 14d vs prior 14d)
        $actionsCompletedRecent = \App\Models\CollaborateurAction::whereIn('collaborateur_id', $collabIds)
            ->where('status', 'termine')
            ->where('completed_at', '>=', now()->subDays(14))
            ->get(['collaborateur_id'])
            ->countBy('collaborateur_id');
        $actionsCompletedPrior = \App\Models\CollaborateurAction::whereIn('collaborateur_id', $collabIds)
            ->where('status', 'termine')
            ->whereBetween('completed_at', [now()->subDays(28), now()->subDays(14)])
            ->get(['collaborateur_id'])
            ->countBy('collaborateur_id');

        // ── Level 1 : rule-based scoring ──
        $signals = [];
        foreach ($collabs as $c) {
            $latestNps = ($npsByCollab[$c->id] ?? collect())->first();
            $npsScore = $latestNps?->score;

            $moodWeeks = $moodByCollabWeek[$c->id] ?? collect();
            $moodAvg7d = (float) ($moodWeeks->where('week_offset', 0)->first()?->avg_mood ?? 0);
            $moodAvgFull = $moodWeeks->avg('avg_mood');

            $score = 0;
            $reasons = [];

            if ($npsScore !== null && $npsScore <= 6) {
                $score += 30;
                $reasons[] = "Détracteur NPS (note {$npsScore}/10)";
            }
            if ($moodAvg7d > 0 && $moodAvg7d < 3) {
                $score += 25;
                $reasons[] = "Humeur moyenne basse (" . round($moodAvg7d, 1) . "/5) cette semaine";
            }
            if ($c->progression < 50 && $c->date_debut && now()->diffInDays($c->date_debut) > 30) {
                $score += 20;
                $reasons[] = "Parcours en retard ({$c->progression}% à J+" . now()->diffInDays($c->date_debut) . ")";
            }

            if ($score < 25) continue;

            // ── Level 3 : pre-compute temporal context ──
            $weeklyMoods = [];
            for ($w = 3; $w >= 0; $w--) {
                $entry = $moodWeeks->where('week_offset', $w)->first();
                $weeklyMoods[] = $entry ? round((float) $entry->avg_mood, 2) : null;
            }

            $velocity = ($actionsCompletedRecent[$c->id] ?? 0) - ($actionsCompletedPrior[$c->id] ?? 0);

            $signals[] = [
                'id' => $c->id,
                'nom' => trim("{$c->prenom} {$c->nom}"),
                'poste' => $c->poste,
                'site' => $c->site,
                'risk_score' => min(100, $score),
                'reasons' => $reasons,
                // Level 2/3 context (sent to Claude for narrative + trend)
                '_context' => [
                    'progression' => $c->progression,
                    'days_since_start' => $c->date_debut ? (int) now()->diffInDays($c->date_debut) : null,
                    'nps_history' => ($npsByCollab[$c->id] ?? collect())->take(3)->map(fn ($n) => [
                        'score' => (int) $n->score,
                        'date' => \Carbon\Carbon::parse($n->completed_at)->format('Y-m-d'),
                        'comment' => $n->comment ? mb_substr($n->comment, 0, 250) : null,
                    ])->values()->toArray(),
                    'mood_weekly_avg' => $weeklyMoods, // [week-3, week-2, week-1, this week] — null si pas de data
                    'mood_recent_comments' => ($moodCommentsByCollab[$c->id] ?? collect())->take(3)->map(fn ($m) => [
                        'mood' => (int) $m->mood,
                        'date' => \Carbon\Carbon::parse($m->created_at)->format('Y-m-d'),
                        'comment' => mb_substr($m->comment, 0, 250),
                    ])->values()->toArray(),
                    'actions_velocity' => [
                        'last_14d' => $actionsCompletedRecent[$c->id] ?? 0,
                        'prior_14d' => $actionsCompletedPrior[$c->id] ?? 0,
                        'delta' => $velocity,
                    ],
                ],
            ];
        }

        usort($signals, fn ($a, $b) => $b['risk_score'] - $a['risk_score']);

        // Cap to top 20 to limit Claude payload
        $topSignals = array_slice($signals, 0, 20);

        // ── Level 2 + 3 : single Claude call enriching all at-risk collabs ──
        $enrichments = [];
        if ($enrich && !empty($topSignals)) {
            $claudeResult = $this->enrichRiskWithClaude($topSignals);
            if (isset($claudeResult['enrichments']) && is_array($claudeResult['enrichments'])) {
                foreach ($claudeResult['enrichments'] as $e) {
                    if (isset($e['id'])) $enrichments[$e['id']] = $e;
                }
            }
        }

        // Merge enrichments into signals + drop _context (internal only)
        $output = array_map(function ($s) use ($enrichments) {
            $e = $enrichments[$s['id']] ?? null;
            unset($s['_context']);
            return array_merge($s, $e ? [
                'narrative' => $e['narrative'] ?? null,
                'trend' => $e['trend'] ?? null,
                'trend_label' => $e['trend_label'] ?? null,
                'targeted_recommendation' => $e['targeted_recommendation'] ?? null,
            ] : []);
        }, $topSignals);

        $this->logUsage('insights', null, ['type' => 'turnover_risk', 'count' => count($output), 'enriched' => $enrich]);

        return response()->json([
            'at_risk' => $output,
            'total_screened' => $collabs->count(),
            'enriched' => $enrich && !empty($enrichments),
        ]);
    }

    /**
     * Single Claude call : analyse the ~20 at-risk collabs and return for each :
     *  - narrative (why they are at risk, in plain French)
     *  - trend (declining|stable|improving) computed from mood_weekly_avg
     *  - trend_label (short phrase)
     *  - targeted_recommendation (concrete action)
     */
    private function enrichRiskWithClaude(array $signals): array
    {
        // Compact payload for Claude — keep only the essential context
        $payload = array_map(function ($s) {
            return [
                'id' => $s['id'],
                'nom' => $s['nom'],
                'poste' => $s['poste'],
                'risk_score' => $s['risk_score'],
                'rule_reasons' => $s['reasons'],
                'context' => $s['_context'] ?? [],
            ];
        }, $signals);

        $systemPrompt = "Tu es un expert RH spécialisé dans la prédiction de turnover. Tu reçois une liste de collaborateurs flaggés à risque par un algorithme de scoring + leur contexte temporel sur 4 semaines. Pour chaque collaborateur, retourne UNIQUEMENT un JSON strict (pas de markdown) :\n"
            . '{"enrichments":[{"id":N,"narrative":"...","trend":"declining|stable|improving|insufficient_data","trend_label":"phrase courte","targeted_recommendation":"action concrète"}]}'."\n"
            . "Règles strictes :\n"
            . "- narrative : 2 phrases max en français, explique POURQUOI ce collab est à risque en croisant les signaux disponibles. Cite les verbatims si pertinents (entre guillemets, max 50 caractères chacun).\n"
            . "- trend : analyse mood_weekly_avg [w-3, w-2, w-1, this week]. Si moyenne baisse de >0.5 entre w-3 et this week → 'declining'. Si stable (<0.3 d'écart) → 'stable'. Si remonte → 'improving'. Si tableau a moins de 2 valeurs non-nulles → 'insufficient_data'.\n"
            . "- trend_label : phrase courte (max 60 chars) ex. 'Humeur en chute depuis 3 semaines' ou 'Stable mais bas'.\n"
            . "- targeted_recommendation : une action SPÉCIFIQUE et datée (max 100 chars). Pas générique. Ex. 'Planifier 1:1 avec son manager dans les 7j pour aborder la charge de travail évoquée le 15/04'.\n"
            . "- Si peu de données, dis-le clairement dans la narrative ('Données limitées : ...').\n"
            . "- Conserve l'ID exact reçu pour chaque collaborateur.";

        $userPrompt = "COLLABORATEURS À RISQUE :\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Bigger token budget — up to 20 collabs × ~250 tokens = 5000 tokens of output
        $result = $this->callClaude($systemPrompt, $userPrompt, 6000);
        if (isset($result['error'])) {
            \Log::warning("Turnover Claude enrich failed: " . $result['error']);
            return [];
        }

        $parsed = $this->parseJson($result['text']);
        $this->logUsage('insights', $result, ['type' => 'turnover_risk_enrich', 'count' => count($signals)]);

        return $parsed ?: [];
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

    /**
     * Block Starter-tier AI plans on heavy multi-collab features.
     * Returns 402 JSON if the tenant only has IA Starter; null otherwise.
     */
    private function blockIfStarter(string $featureLabel): ?JsonResponse
    {
        $tenant = tenant();
        if (!$tenant) return null;

        $aiSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn ($q) => $q->where('addon_type', 'ai'))
            ->with('plan')
            ->first();

        // No AI plan at all is handled by AiUsageGuard::blockIfExceeded — let it pass through
        if (!$aiSub) return null;

        $slug = $aiSub->plan->slug ?? '';
        $isStarter = str_contains($slug, 'starter') || str_contains($slug, 'ia_starter');

        if ($isStarter) {
            return response()->json([
                'error' => "{$featureLabel} : disponible à partir du plan IA Business.",
                'tier_required' => 'business',
                'current_plan' => $aiSub->plan->nom ?? null,
                'feature' => $featureLabel,
            ], 402);
        }
        return null;
    }
}
