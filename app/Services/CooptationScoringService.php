<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Cooptation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Computes a 0..100 priority score for a cooptation, plus a short narrative
 * reason and a suggested next action. Backed by Claude Haiku 4.5.
 *
 * The scorer combines:
 *  - candidate identity + parsed CV (if available, see CvParsingService)
 *  - campaign description + reward
 *  - time signals (days waiting, SLA breach)
 *  - referrer track record (count of past successful cooptations by the same parrain)
 *
 * Output is persisted on the Cooptation row + a row in `ai_usages`
 * (re-using the existing chat usage table for unified billing/observability).
 */
class CooptationScoringService
{
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const MAX_TOKENS = 600;

    public function score(Cooptation $cooptation): ?array
    {
        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            Log::warning('CooptationScoringService: ANTHROPIC_API_KEY missing — skipping score');
            return null;
        }

        // Quota / spending cap — best-effort: we do not surface a 402 here since the
        // caller is a background scoring job. We just skip silently if exceeded so
        // the cooptation still gets created, just without an AI score.
        $status = \App\Services\AiUsageGuard::status('cooptation_score');
        if ($status['exceeded']) {
            Log::info('CooptationScoringService: AI quota exceeded — skipping score', [
                'used' => $status['used'], 'limit' => $status['effective_limit'],
            ]);
            return null;
        }

        // Eager-load campaign to inject job context.
        $cooptation->loadMissing('campaign');
        $campaign = $cooptation->campaign;

        // Past hires by the same parrain — strong signal: "this person knows how to recommend"
        $referrerStats = Cooptation::where('referrer_email', $cooptation->referrer_email)
            ->where('id', '!=', $cooptation->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN statut IN ('valide','recompense_versee') THEN 1 ELSE 0 END) as success
            ")
            ->first();
        $referrerSuccessCount = (int) ($referrerStats->success ?? 0);
        $referrerTotalCount = (int) ($referrerStats->total ?? 0);

        $daysWaiting = $cooptation->date_cooptation
            ? Carbon::parse($cooptation->date_cooptation)->diffInDays(now())
            : 0;

        $cvSummary = $this->cvSummary($cooptation->cv_parsed_data);

        // Build a structured prompt — strict JSON output.
        $userMessage = $this->buildPrompt($cooptation, $campaign, $cvSummary, $daysWaiting, $referrerSuccessCount, $referrerTotalCount);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => self::MODEL,
                'max_tokens' => self::MAX_TOKENS,
                'system' => $this->systemPrompt(),
                'messages' => [['role' => 'user', 'content' => $userMessage]],
            ]);

            if (!$response->successful()) {
                Log::error('CooptationScoringService: Claude error', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $body = $response->json();
            $text = $body['content'][0]['text'] ?? '';
            $parsed = $this->extractJson($text);
            if (!$parsed || !isset($parsed['score'])) {
                Log::warning('CooptationScoringService: malformed JSON from Claude', ['raw' => $text]);
                return null;
            }

            $score = max(0, min(100, (float) $parsed['score']));
            $reason = mb_substr((string) ($parsed['reason'] ?? ''), 0, 400);
            $action = mb_substr((string) ($parsed['action'] ?? 'Examiner'), 0, 60);

            $cooptation->update([
                'priority_score' => $score,
                'priority_reason' => $reason,
                'priority_action' => $action,
                'priority_computed_at' => now(),
                'priority_model_version' => self::MODEL,
            ]);

            // Cost tracking — Haiku 4.5 pricing as of 2026 (input $0.80/M, output $4/M)
            $inTok = $body['usage']['input_tokens'] ?? 0;
            $outTok = $body['usage']['output_tokens'] ?? 0;
            $cost = ($inTok * 0.80 + $outTok * 4.0) / 1_000_000;
            try {
                AiUsage::create([
                    'type' => 'cooptation_score',
                    'user_id' => null,
                    'model' => self::MODEL,
                    'input_tokens' => $inTok,
                    'output_tokens' => $outTok,
                    'cost_usd' => $cost,
                    'metadata' => json_encode(['cooptation_id' => $cooptation->id]),
                ]);
            } catch (\Throwable $e) { /* table missing on some tenants — non-blocking */ }

            return ['score' => $score, 'reason' => $reason, 'action' => $action];
        } catch (\Throwable $e) {
            Log::error('CooptationScoringService exception', ['error' => $e->getMessage(), 'cooptation_id' => $cooptation->id]);
            return null;
        }
    }

    private function systemPrompt(): string
    {
        return <<<TXT
Tu es un·e recruteur·euse senior qui priorise les cooptations à traiter en priorité.
Tu analyses le profil candidat, la fiche de poste, le contexte temps et le track record du parrain.
Tu réponds STRICTEMENT en JSON valide, rien d'autre, format :
{"score": 0-100, "reason": "raison courte en français (max 200 caractères)", "action": "action courte parmi: Programmer entretien | Examiner | Faire une offre | Relancer parrain | Refuser"}
Critères de scoring :
- Adéquation profil/poste (skills, expérience, secteur) : 40%
- Urgence (jours d'attente, SLA) : 25%
- Confiance dans le parrain (historique de succès) : 20%
- Complétude du dossier (CV, LinkedIn, message) : 15%
Score 90+ = à traiter MAINTENANT. 70-89 = priorité haute. 50-69 = standard. <50 = basse priorité.
TXT;
    }

    private function buildPrompt(Cooptation $c, $campaign, string $cvSummary, int $daysWaiting, int $refSuccess, int $refTotal): string
    {
        $jobBlock = $campaign
            ? "POSTE : {$campaign->titre}\nDescription : " . ($campaign->description ?: '(non renseignée)') . "\nDépartement : " . ($campaign->departement ?: 'n/a') . " · Site : " . ($campaign->site ?: 'n/a') . " · Contrat : " . ($campaign->type_contrat ?: 'n/a')
            : "POSTE VISÉ : " . ($c->candidate_poste ?: '(non précisé)') . "\n(Pas de campagne associée.)";

        $candidateBlock = "CANDIDAT : {$c->candidate_name}"
            . "\nEmail : {$c->candidate_email}"
            . ($c->telephone ? "\nTéléphone : {$c->telephone}" : '')
            . ($c->linkedin_url ? "\nLinkedIn : {$c->linkedin_url}" : '')
            . ($c->cv_original_name ? "\nCV joint : {$c->cv_original_name}" : '')
            . ($cvSummary ? "\n\nProfil parsé du CV :\n{$cvSummary}" : '')
            . ($c->notes ? "\n\nNote du parrain : " . mb_substr($c->notes, 0, 500) : '');

        $contextBlock = "CONTEXTE\nStatut actuel : {$c->statut}"
            . "\nJours depuis cooptation : {$daysWaiting}"
            . "\nParrain : {$c->referrer_name} ({$c->referrer_email})"
            . "\nHistorique parrain : {$refSuccess} embauches confirmées sur {$refTotal} cooptations";

        return "{$jobBlock}\n\n{$candidateBlock}\n\n{$contextBlock}\n\nRetourne le JSON décrit dans tes instructions système.";
    }

    private function cvSummary($parsed): string
    {
        if (!is_array($parsed) || empty($parsed)) return '';
        $lines = [];
        if (!empty($parsed['current_role'])) $lines[] = "Poste actuel : {$parsed['current_role']}";
        if (!empty($parsed['experience_years'])) $lines[] = "Expérience : {$parsed['experience_years']} ans";
        if (!empty($parsed['skills']) && is_array($parsed['skills'])) $lines[] = "Skills : " . implode(', ', array_slice($parsed['skills'], 0, 12));
        if (!empty($parsed['education']) && is_array($parsed['education'])) $lines[] = "Formation : " . implode(' · ', array_slice($parsed['education'], 0, 3));
        if (!empty($parsed['languages']) && is_array($parsed['languages'])) $lines[] = "Langues : " . implode(', ', $parsed['languages']);
        return implode("\n", $lines);
    }

    /**
     * Extract the first JSON object from a model response (Claude sometimes
     * wraps JSON in markdown fences or adds prose before/after).
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        // Strip markdown fences
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        // Find first {...} block
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }
}
