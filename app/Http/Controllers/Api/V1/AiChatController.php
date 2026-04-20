<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AiUsage;
use App\Models\Subscription;

class AiChatController extends Controller
{
    /**
     * Handle an AI chat message from the employee assistant.
     * Sends the conversation to Claude and returns the reply.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'array|max:50',
            'history.*.role' => 'required|string|in:user,assistant',
            'history.*.content' => 'required|string|max:4000',
        ]);

        // Check AI plan (usage-based, no quota)
        if (!$this->hasActiveAiPlan()) {
            return response()->json([
                'reply' => "L'assistant IA n'est pas disponible. Contactez votre administrateur pour activer le module IA.",
                'no_plan' => true,
            ]);
        }

        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return response()->json([
                'reply' => "L'assistant IA n'est pas configuré. La clé API est manquante.",
            ], 500);
        }

        // Build context about the user
        $user = $request->user();
        $collaborateur = null;
        try {
            $collaborateur = \App\Models\Collaborateur::where('email', $user->email)->first();
        } catch (\Exception $e) {}

        $systemPrompt = $this->buildSystemPrompt($collaborateur);

        // Build messages array for Claude
        $messages = [];
        $history = $request->input('history', []);
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Use Haiku for chat (fast + cheap)
        $model = 'claude-haiku-4-5-20251001';

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => $messages,
            ]);

            if (!$response->successful()) {
                Log::error('Claude Chat API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return response()->json([
                    'reply' => "Désolé, une erreur est survenue avec l'assistant IA. Veuillez réessayer.",
                ], 502);
            }

            $result = $response->json();
            $reply = $result['content'][0]['text'] ?? 'Désolé, je n\'ai pas pu générer de réponse.';

            // Track usage
            $inputTokens = $result['usage']['input_tokens'] ?? 0;
            $outputTokens = $result['usage']['output_tokens'] ?? 0;
            $costUsd = $this->estimateCost($model, $inputTokens, $outputTokens);

            AiUsage::create([
                'type' => 'bot_message',
                'user_id' => $user->id,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $costUsd,
                'metadata' => json_encode([
                    'message_length' => strlen($request->input('message')),
                    'history_length' => count($history),
                ]),
            ]);

            return response()->json([
                'reply' => $reply,
                'tokens' => ['input' => $inputTokens, 'output' => $outputTokens],
            ]);

        } catch (\Exception $e) {
            Log::error('AI Chat exception', ['error' => $e->getMessage()]);
            return response()->json([
                'reply' => "Désolé, l'assistant IA est temporairement indisponible. Veuillez réessayer plus tard.",
            ], 500);
        }
    }

    /**
     * Handle an AI chat message from the admin assistant.
     */
    public function adminChat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'array|max:50',
            'history.*.role' => 'required|string|in:user,assistant',
            'history.*.content' => 'required|string|max:4000',
        ]);

        if (!$this->hasActiveAiPlan()) {
            return response()->json(['reply' => "Module IA non activé. Souscrivez un add-on IA."]);
        }

        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return response()->json(['reply' => "Clé API Anthropic manquante."], 500);
        }

        $systemPrompt = $this->buildAdminSystemPrompt();

        $messages = [];
        foreach ($request->input('history', []) as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $model = 'claude-haiku-4-5-20251001';

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 2048,
                'system' => $systemPrompt,
                'messages' => $messages,
            ]);

            if (!$response->successful()) {
                Log::error('Claude Admin Chat API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json(['reply' => "Erreur API IA."], 502);
            }

            $result = $response->json();
            $reply = $result['content'][0]['text'] ?? 'Pas de réponse.';

            $inputTokens = $result['usage']['input_tokens'] ?? 0;
            $outputTokens = $result['usage']['output_tokens'] ?? 0;

            AiUsage::create([
                'type' => 'bot_message',
                'user_id' => $request->user()->id,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $this->estimateCost($model, $inputTokens, $outputTokens),
                'metadata' => json_encode(['context' => 'admin_chat']),
            ]);

            return response()->json(['reply' => $reply]);

        } catch (\Exception $e) {
            Log::error('Admin AI Chat exception', ['error' => $e->getMessage()]);
            return response()->json(['reply' => "Assistant IA temporairement indisponible."], 500);
        }
    }

    /**
     * Generate AI-powered insights for the admin dashboard.
     */
    public function getInsights(Request $request): JsonResponse
    {
        try {
        if (!$this->hasActiveAiPlan()) {
            return response()->json(['insights' => [], 'reason' => 'no_plan']);
        }

        if ($this->isStarterPlan()) {
            return response()->json(['insights' => [], 'reason' => 'plan_upgrade_required', 'message' => 'Les insights IA sont disponibles à partir du plan IA Business.']);
        }

        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return response()->json(['insights' => []]);
        }

        // Build data summary for analysis
        $dataContext = "";

        try {
            $collabs = \App\Models\Collaborateur::with(['parcours', 'manager:id,prenom,nom'])->get();
            $total = $collabs->count();
            $enRetard = $collabs->where('status', 'en_retard');
            $enCours = $collabs->where('status', 'en_cours');
            $termines = $collabs->where('status', 'termine');
            $avgProg = $total > 0 ? round($collabs->avg('progression'), 1) : 0;

            $dataContext .= "COLLABORATEURS ({$total} total) :\n";
            $dataContext .= "- En cours : {$enCours->count()} | En retard : {$enRetard->count()} | Terminés : {$termines->count()}\n";
            $dataContext .= "- Progression moyenne : {$avgProg}%\n";

            if ($enRetard->count() > 0) {
                $dataContext .= "\nEN RETARD :\n";
                foreach ($enRetard as $c) {
                    $days = $c->date_debut ? (int)now()->diffInDays(\Carbon\Carbon::parse($c->date_debut)) : 0;
                    $dataContext .= "- {$c->prenom} {$c->nom}, {$c->poste}, {$c->site}, {$c->progression}%, début il y a {$days} jours\n";
                }
            }

            // Docs manquants
            $docsMissing = $collabs->filter(fn($c) => $c->status !== 'termine' && ($c->docs_valides ?? 0) < ($c->docs_total ?? 0));
            if ($docsMissing->count() > 0) {
                $dataContext .= "\nDOCS MANQUANTS :\n";
                foreach ($docsMissing->take(10) as $c) {
                    $missing = ($c->docs_total ?? 0) - ($c->docs_valides ?? 0);
                    $dataContext .= "- {$c->prenom} {$c->nom} : {$missing} document(s) manquant(s)\n";
                }
            }

            // Périodes d'essai
            $now = now();
            $essais = $collabs->filter(function ($c) use ($now) {
                if (!$c->date_fin_essai) return false;
                $fin = \Carbon\Carbon::parse($c->date_fin_essai);
                return $fin->isBetween($now, $now->copy()->addDays(60));
            });
            if ($essais->count() > 0) {
                $dataContext .= "\nPÉRIODES D'ESSAI (< 60 jours) :\n";
                foreach ($essais as $c) {
                    $jours = (int)\Carbon\Carbon::parse($c->date_fin_essai)->diffInDays($now);
                    $dataContext .= "- {$c->prenom} {$c->nom} : fin dans {$jours} jours ({$c->date_fin_essai})\n";
                }
            }

            // Parcours
            $parcours = \App\Models\Parcours::withCount('collaborateurs')->get();
            $dataContext .= "\nPARCOURS :\n";
            foreach ($parcours as $p) {
                $dataContext .= "- {$p->nom} ({$p->status}) : {$p->collaborateurs_count} collaborateurs\n";
            }

            // Actions en retard
            $actionsRetard = \App\Models\CollaborateurAction::where('status', 'a_faire')
                ->where('created_at', '<', now()->subDays(7))
                ->with(['action:id,titre', 'collaborateur:id,prenom,nom'])
                ->take(15)->get();
            if ($actionsRetard->count() > 0) {
                $dataContext .= "\nACTIONS EN RETARD (>7j) :\n";
                foreach ($actionsRetard as $ca) {
                    $dataContext .= "- {$ca->collaborateur->prenom} {$ca->collaborateur->nom} : {$ca->action->titre}\n";
                }
            }

            // Date du jour
            $dataContext .= "\nDate actuelle : " . now()->format('d/m/Y') . "\n";

        } catch (\Exception $e) {
            Log::warning('AI Insights data error', ['error' => $e->getMessage()]);
            return response()->json(['insights' => []]);
        }

        $systemPrompt = <<<'PROMPT'
Tu es un analyste RH expert. À partir des données ci-dessous, génère 3 à 6 insights actionnables en JSON.

Réponds UNIQUEMENT avec un JSON array :
[
  { "type": "danger|warning|success|info", "title": "Titre court", "message": "Analyse détaillée avec recommandation concrète", "priority": "high|medium|low" }
]

Règles :
- "danger" : problème urgent nécessitant une action immédiate
- "warning" : situation à surveiller
- "success" : bonne nouvelle ou progrès notable
- "info" : tendance ou recommandation proactive
- Sois spécifique : cite des noms, des chiffres, des pourcentages
- Donne des recommandations concrètes (pas juste "il faut agir")
- Priorise : retards critiques > docs manquants > périodes d'essai > tendances
- Si tout va bien, félicite et suggère des améliorations
- Génère exactement 5 à 8 insights, couvrant différents aspects (retards, documents, parcours, tendances, recommandations)
PROMPT;

        $model = 'claude-haiku-4-5-20251001';

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(20)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $dataContext]],
            ]);

            if (!$response->successful()) {
                return response()->json(['insights' => []]);
            }

            $result = $response->json();
            $text = $result['content'][0]['text'] ?? '';

            // Parse JSON
            preg_match('/\[[\s\S]*\]/', $text, $matches);
            $insights = $matches ? json_decode($matches[0], true) : [];

            // Track usage
            AiUsage::create([
                'type' => 'bot_message',
                'user_id' => $request->user()->id,
                'model' => $model,
                'input_tokens' => $result['usage']['input_tokens'] ?? 0,
                'output_tokens' => $result['usage']['output_tokens'] ?? 0,
                'cost_usd' => $this->estimateCost($model, $result['usage']['input_tokens'] ?? 0, $result['usage']['output_tokens'] ?? 0),
                'metadata' => json_encode(['context' => 'insights']),
            ]);

            return response()->json(['insights' => $insights ?? []]);

        } catch (\Exception $e) {
            Log::error('AI Insights exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['insights' => [], 'reason' => 'error', 'debug' => $e->getMessage()]);
        }
        } catch (\Throwable $e) {
            Log::error('AI Insights fatal', ['error' => $e->getMessage()]);
            return response()->json(['insights' => [], 'reason' => 'error']);
        }
    }

    /**
     * Generate a complete parcours (phases + actions) from a text prompt.
     */
    public function generateParcours(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|max:2000',
        ]);

        if (!$this->hasActiveAiPlan()) {
            return response()->json(['error' => 'Module IA non activé.'], 403);
        }

        if ($this->isStarterPlan()) {
            return response()->json(['error' => 'La génération de parcours IA est disponible à partir du plan IA Business.'], 403);
        }

        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return response()->json(['error' => 'Clé API manquante.'], 500);
        }

        $systemPrompt = <<<'PROMPT'
Tu es un expert RH qui crée des parcours d'onboarding/offboarding/crossboarding/reboarding pour la plateforme Illizeo.

Génère un parcours complet en JSON avec cette structure exacte :
{
  "nom": "Nom du parcours",
  "categorie": "onboarding|offboarding|crossboarding|reboarding",
  "phases": [
    { "nom": "Nom de la phase", "delaiDebut": "J-30", "delaiFin": "J-1" }
  ],
  "actions": [
    { "titre": "Titre de l'action", "type": "document|formation|questionnaire|tache|formulaire|signature|lecture|entretien|checklist_it|passation|visite", "phase": "Nom de la phase", "delaiRelatif": "J+0", "obligatoire": true, "description": "Description courte" }
  ]
}

Règles :
- Crée 3 à 6 phases avec des délais réalistes (J-30 à J+90)
- Crée 8 à 20 actions variées réparties dans les phases
- Les types d'actions doivent être variés (document, formation, questionnaire, tache, signature, entretien, etc.)
- Adapte le contenu au contexte décrit (poste, site, type de contrat, etc.)
- Les descriptions doivent être concrètes et actionnables
- Marque comme obligatoire les actions essentielles (docs administratifs, signatures, formations réglementaires)
- Réponds UNIQUEMENT avec le JSON, pas de texte autour
PROMPT;

        $model = 'claude-haiku-4-5-20251001';

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 4096,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $request->prompt],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Claude Generate Parcours error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json(['error' => 'Erreur API IA.'], 502);
            }

            $result = $response->json();
            $text = $result['content'][0]['text'] ?? '';

            // Extract JSON from response
            $jsonMatch = preg_match('/\{[\s\S]*\}/', $text, $matches);
            if (!$jsonMatch) {
                return response()->json(['error' => 'Impossible de parser la réponse IA.', 'raw' => $text], 422);
            }

            $parcours = json_decode($matches[0], true);
            if (!$parcours || !isset($parcours['nom'])) {
                return response()->json(['error' => 'JSON invalide dans la réponse IA.', 'raw' => $text], 422);
            }

            // Track usage
            $inputTokens = $result['usage']['input_tokens'] ?? 0;
            $outputTokens = $result['usage']['output_tokens'] ?? 0;
            AiUsage::create([
                'type' => 'bot_message',
                'user_id' => $request->user()->id,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $this->estimateCost($model, $inputTokens, $outputTokens),
                'metadata' => json_encode(['context' => 'generate_parcours', 'prompt_length' => strlen($request->prompt)]),
            ]);

            return response()->json(['parcours' => $parcours]);

        } catch (\Exception $e) {
            Log::error('Generate Parcours exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erreur lors de la génération.'], 500);
        }
    }

    /**
     * Build admin system prompt with all tenant data.
     */
    private function buildAdminSystemPrompt(): string
    {
        $context = "Tu es l'assistant IA d'Illizeo pour les administrateurs RH.\n";
        $context .= "Tu as accès aux données réelles de l'entreprise ci-dessous. Utilise-les pour répondre précisément.\n";
        $context .= "Réponds de manière concise et professionnelle. Utilise des listes et des chiffres.\n";
        $context .= "Ne dis JAMAIS que tu n'as pas accès aux données. Tu les as.\n\n";

        // ── Collaborateurs ──
        try {
            $collabs = \App\Models\Collaborateur::with(['parcours', 'manager:id,prenom,nom'])->get();
            $total = $collabs->count();
            $enCours = $collabs->where('status', 'en_cours')->count();
            $enRetard = $collabs->where('status', 'en_retard')->count();
            $termines = $collabs->where('status', 'termine')->count();
            $avgProgress = $total > 0 ? round($collabs->avg('progression'), 1) : 0;

            $context .= "=== COLLABORATEURS ({$total} total) ===\n";
            $context .= "En cours : {$enCours} | En retard : {$enRetard} | Terminés : {$termines}\n";
            $context .= "Progression moyenne : {$avgProgress}%\n\n";

            // Détail des collaborateurs en retard
            $retards = $collabs->where('status', 'en_retard');
            if ($retards->count() > 0) {
                $context .= "COLLABORATEURS EN RETARD :\n";
                foreach ($retards->take(20) as $c) {
                    $manager = $c->manager ? "{$c->manager->prenom} {$c->manager->nom}" : 'pas de manager';
                    $context .= "  - {$c->prenom} {$c->nom} — {$c->poste} — {$c->site} — {$c->progression}% — Manager: {$manager}\n";
                }
                $context .= "\n";
            }

            // Tous les collaborateurs en cours
            $actifs = $collabs->where('status', 'en_cours');
            if ($actifs->count() > 0) {
                $context .= "COLLABORATEURS EN COURS :\n";
                foreach ($actifs->take(30) as $c) {
                    $parcours = $c->parcours->nom ?? 'pas de parcours';
                    $context .= "  - {$c->prenom} {$c->nom} — {$c->poste} — {$c->site} — {$c->progression}% — Parcours: {$parcours}";
                    if ($c->date_fin_essai) $context .= " — Fin essai: {$c->date_fin_essai}";
                    $context .= "\n";
                }
                $context .= "\n";
            }

            // Périodes d'essai proches
            $now = now();
            $essaiProche = $collabs->filter(function ($c) use ($now) {
                if (!$c->date_fin_essai) return false;
                $fin = \Carbon\Carbon::parse($c->date_fin_essai);
                return $fin->isBetween($now, $now->copy()->addDays(30));
            });
            if ($essaiProche->count() > 0) {
                $context .= "PÉRIODES D'ESSAI SE TERMINANT DANS 30 JOURS :\n";
                foreach ($essaiProche as $c) {
                    $context .= "  - {$c->prenom} {$c->nom} — fin: {$c->date_fin_essai}\n";
                }
                $context .= "\n";
            }
        } catch (\Exception $e) {
            $context .= "Erreur chargement collaborateurs: {$e->getMessage()}\n";
        }

        // ── Documents manquants ──
        try {
            $collabsWithDocs = \App\Models\Collaborateur::where('status', '!=', 'termine')
                ->whereColumn('docs_valides', '<', 'docs_total')->get();
            if ($collabsWithDocs->count() > 0) {
                $context .= "=== DOCUMENTS MANQUANTS ===\n";
                foreach ($collabsWithDocs->take(20) as $c) {
                    $missing = ($c->docs_total ?? 0) - ($c->docs_valides ?? 0);
                    $context .= "  - {$c->prenom} {$c->nom} — {$missing} document(s) manquant(s) sur {$c->docs_total}\n";
                }
                $context .= "\n";
            }
        } catch (\Exception $e) {}

        // ── Parcours ──
        try {
            $parcours = \App\Models\Parcours::withCount('collaborateurs')->get();
            if ($parcours->count() > 0) {
                $context .= "=== PARCOURS ({$parcours->count()}) ===\n";
                foreach ($parcours as $p) {
                    $context .= "  - {$p->nom} — {$p->collaborateurs_count} collaborateur(s) actif(s) — Statut: {$p->status}\n";
                }
                $context .= "\n";
            }
        } catch (\Exception $e) {}

        // ── Actions en retard ──
        try {
            $actionsRetard = \App\Models\CollaborateurAction::where('status', 'a_faire')
                ->whereNotNull('created_at')
                ->where('created_at', '<', now()->subDays(7))
                ->with(['action:id,titre,obligatoire', 'collaborateur:id,prenom,nom'])
                ->take(20)->get();
            if ($actionsRetard->count() > 0) {
                $context .= "=== ACTIONS EN RETARD (>7 jours) ===\n";
                foreach ($actionsRetard as $ca) {
                    $collab = $ca->collaborateur ? "{$ca->collaborateur->prenom} {$ca->collaborateur->nom}" : '?';
                    $action = $ca->action->titre ?? '?';
                    $oblig = $ca->action->obligatoire ?? false ? ' [OBLIGATOIRE]' : '';
                    $context .= "  - {$collab} — {$action}{$oblig}\n";
                }
                $context .= "\n";
            }
        } catch (\Exception $e) {}

        // ── Stats IA ──
        try {
            $aiUsage = AiUsage::currentMonthSummary();
            $context .= "=== CONSOMMATION IA CE MOIS ===\n";
            $context .= "OCR: " . ($aiUsage['ocr_scans'] ?? 0) . " | Bot: " . ($aiUsage['bot_messages'] ?? 0) . " | Contrats: " . ($aiUsage['contrat_generations'] ?? 0) . "\n\n";
        } catch (\Exception $e) {}

        // ── Company info ──
        try {
            $settings = \App\Models\CompanySetting::whereIn('key', ['company_name', 'sector', 'company_size'])
                ->pluck('value', 'key');
            if ($settings->isNotEmpty()) {
                $context .= "=== ENTREPRISE ===\n";
                if ($settings->has('company_name')) $context .= "Nom : " . $settings['company_name'] . "\n";
                if ($settings->has('sector')) $context .= "Secteur : " . $settings['sector'] . "\n";
                if ($settings->has('company_size')) $context .= "Taille : " . $settings['company_size'] . "\n";
            }
        } catch (\Exception $e) {}

        $context .= "\n=== INSTRUCTIONS ===\n";
        $context .= "- Utilise TOUJOURS les données ci-dessus pour répondre.\n";
        $context .= "- Donne des noms, des chiffres précis, des pourcentages.\n";
        $context .= "- Pour les alertes, priorise : retards > documents manquants > périodes d'essai.\n";
        $context .= "- Sois proactif : si tu vois un problème, mentionne-le même si ce n'est pas la question.\n";
        $context .= "- Vouvoie l'administrateur.\n";

        return $context;
    }

    /**
     * Build the system prompt with full contextual data about the employee.
     */
    private function buildSystemPrompt($collaborateur): string
    {
        $context = "Tu es l'assistant IA d'Illizeo, une plateforme SaaS de gestion RH et d'onboarding.\n";
        $context .= "Tu aides les collaborateurs à s'intégrer dans leur entreprise.\n";
        $context .= "Réponds de manière concise, professionnelle et bienveillante. En français sauf si le collaborateur parle en anglais.\n";
        $context .= "Tu as accès aux données réelles du collaborateur ci-dessous. Utilise-les pour donner des réponses précises et personnalisées.\n";
        $context .= "Ne dis JAMAIS que tu n'as pas accès aux données ou que tu ne peux pas voir les tâches. Tu les as.\n";
        $context .= "Si une donnée n'est pas dans le contexte ci-dessous, oriente vers le manager ou HRBP.\n\n";

        if (!$collaborateur) {
            $context .= "Aucun profil collaborateur trouvé pour cet utilisateur.\n";
            return $context;
        }

        // Load all relationships
        try {
            $collaborateur->load([
                'parcours.phases',
                'assignedActions.action.phase',
                'documents.categorie',
                'accompagnants',
                'manager:id,prenom,nom,poste,email',
                'hrManager:id,prenom,nom,poste,email',
            ]);
        } catch (\Exception $e) {
            Log::warning('AI Chat: failed to load relations', ['error' => $e->getMessage()]);
        }

        // ── Profil ──
        $context .= "=== PROFIL DU COLLABORATEUR ===\n";
        $context .= "Prénom : " . ($collaborateur->prenom ?? '?') . "\n";
        $context .= "Nom : " . ($collaborateur->nom ?? '?') . "\n";
        $context .= "Poste : " . ($collaborateur->poste ?? 'non défini') . "\n";
        $context .= "Site : " . ($collaborateur->site ?? 'non défini') . "\n";
        $context .= "Département : " . ($collaborateur->departement ?? 'non défini') . "\n";
        $context .= "Date de début : " . ($collaborateur->date_debut ?? $collaborateur->dateDebut ?? 'non définie') . "\n";
        $context .= "Statut : " . ($collaborateur->status ?? 'inconnu') . "\n";
        $context .= "Progression globale : " . ($collaborateur->progression ?? 0) . "%\n";
        if ($collaborateur->type_contrat) $context .= "Type de contrat : " . $collaborateur->type_contrat . "\n";
        if ($collaborateur->taux_activite) $context .= "Taux d'activité : " . $collaborateur->taux_activite . "%\n";
        if ($collaborateur->periode_essai) $context .= "Période d'essai : " . $collaborateur->periode_essai . "\n";
        if ($collaborateur->date_fin_essai) $context .= "Fin de période d'essai : " . $collaborateur->date_fin_essai . "\n";

        // ── Parcours ──
        if ($collaborateur->parcours) {
            $context .= "\n=== PARCOURS D'INTÉGRATION ===\n";
            $context .= "Nom du parcours : " . $collaborateur->parcours->nom . "\n";
            $cat = $collaborateur->parcours->categorie ?? null;
            if ($cat) $context .= "Type : " . (is_object($cat) ? $cat->nom : $cat) . "\n";

            // Phases
            $phases = $collaborateur->parcours->phases ?? collect();
            if ($phases->count() > 0) {
                $context .= "Phases du parcours :\n";
                foreach ($phases as $i => $phase) {
                    $context .= "  " . ($i + 1) . ". " . ($phase->nom ?? 'Phase ' . ($i + 1)) . "\n";
                }
            }
        }

        // ── Actions / Tâches ──
        $actions = $collaborateur->assignedActions ?? collect();
        if ($actions->count() > 0) {
            $todo = $actions->filter(fn($a) => in_array($a->status, ['a_faire', 'en_cours']));
            $done = $actions->filter(fn($a) => $a->status === 'termine');

            $context .= "\n=== ACTIONS & TÂCHES ({$actions->count()} total, {$done->count()} terminées, {$todo->count()} restantes) ===\n";

            if ($todo->count() > 0) {
                $context .= "\nActions À FAIRE :\n";
                foreach ($todo->take(20) as $ca) {
                    $action = $ca->action;
                    if (!$action) continue;
                    $type = $action->actionType->nom ?? $action->type ?? 'tâche';
                    $phase = $action->phase->nom ?? '';
                    $obligatoire = $action->obligatoire ? ' [OBLIGATOIRE]' : '';
                    $context .= "  - [{$type}]{$obligatoire} {$action->titre}";
                    if ($phase) $context .= " (phase: {$phase})";
                    if ($action->delai_relatif) $context .= " — échéance: {$action->delai_relatif}";
                    if ($ca->status === 'en_cours') $context .= " — EN COURS";
                    $context .= "\n";
                    if ($action->description) $context .= "    Description: " . mb_substr($action->description, 0, 150) . "\n";
                }
            }

            if ($done->count() > 0) {
                $context .= "\nActions TERMINÉES :\n";
                foreach ($done->take(15) as $ca) {
                    $action = $ca->action;
                    if (!$action) continue;
                    $context .= "  - ✓ {$action->titre}";
                    if ($ca->completed_at) $context .= " (terminé le " . \Carbon\Carbon::parse($ca->completed_at)->format('d/m/Y') . ")";
                    $context .= "\n";
                }
            }
        }

        // ── Documents ──
        $docs = $collaborateur->documents ?? collect();
        if ($docs->count() > 0) {
            $context .= "\n=== DOCUMENTS ADMINISTRATIFS ({$docs->count()}) ===\n";
            foreach ($docs as $doc) {
                $cat = $doc->categorie->nom ?? 'Sans catégorie';
                $status = match($doc->status ?? '') {
                    'valide' => '✓ Validé',
                    'soumis', 'en_attente' => '⏳ En attente de validation',
                    'refuse' => '✗ Refusé' . ($doc->refuse_motif ? " ({$doc->refuse_motif})" : ''),
                    default => '○ Non soumis',
                };
                $oblig = $doc->obligatoire ? ' [OBLIGATOIRE]' : '';
                $context .= "  - {$doc->nom}{$oblig} — {$status} — Catégorie: {$cat}\n";
            }
        }

        // Summary of document status
        $docsValides = $collaborateur->docs_valides ?? 0;
        $docsTotal = $collaborateur->docs_total ?? 0;
        if ($docsTotal > 0) {
            $docsMissing = $docsTotal - $docsValides;
            $context .= "\nRésumé documents : {$docsValides}/{$docsTotal} validés, {$docsMissing} restants.\n";
        }

        // ── Équipe d'accompagnement ──
        $context .= "\n=== ÉQUIPE D'ACCOMPAGNEMENT ===\n";
        $roleLabels = ['manager' => 'Manager', 'hrbp' => 'HRBP', 'buddy' => 'Buddy / Parrain', 'it' => 'Support IT', 'recruteur' => 'Recruteur'];

        if ($collaborateur->manager) {
            $m = $collaborateur->manager;
            $context .= "Manager direct : {$m->prenom} {$m->nom}" . ($m->email ? " ({$m->email})" : "") . "\n";
        }
        if ($collaborateur->hrManager) {
            $hr = $collaborateur->hrManager;
            $context .= "HRBP : {$hr->prenom} {$hr->nom}" . ($hr->email ? " ({$hr->email})" : "") . "\n";
        }

        $accompagnants = $collaborateur->accompagnants ?? collect();
        foreach ($accompagnants as $acc) {
            $role = $roleLabels[$acc->pivot->role ?? ''] ?? ($acc->pivot->role ?? 'Membre');
            $context .= "{$role} : {$acc->name}" . ($acc->email ? " ({$acc->email})" : "") . "\n";
        }

        if (!$collaborateur->manager && !$collaborateur->hrManager && $accompagnants->isEmpty()) {
            $context .= "Aucun accompagnant assigné.\n";
        }

        // ── Infos contrat ──
        if ($collaborateur->type_contrat || $collaborateur->convention_collective) {
            $context .= "\n=== INFORMATIONS CONTRACTUELLES ===\n";
            if ($collaborateur->type_contrat) $context .= "Type : {$collaborateur->type_contrat}\n";
            if ($collaborateur->convention_collective) $context .= "Convention collective : {$collaborateur->convention_collective}\n";
            if ($collaborateur->taux_activite) $context .= "Taux : {$collaborateur->taux_activite}%\n";
        }

        // ── Company settings (infos pratiques) ──
        try {
            $settings = \App\Models\CompanySetting::whereIn('key', [
                'company_name', 'sector', 'site_principal', 'company_size',
                'wifi_info', 'parking_info', 'cantine_info', 'dress_code',
            ])->pluck('value', 'key');

            if ($settings->isNotEmpty()) {
                $context .= "\n=== INFORMATIONS ENTREPRISE ===\n";
                if ($settings->has('company_name')) $context .= "Entreprise : " . $settings['company_name'] . "\n";
                if ($settings->has('sector')) $context .= "Secteur : " . $settings['sector'] . "\n";
                if ($settings->has('site_principal')) $context .= "Site principal : " . $settings['site_principal'] . "\n";
                if ($settings->has('company_size')) $context .= "Taille : " . $settings['company_size'] . " collaborateurs\n";
                if ($settings->has('wifi_info')) $context .= "WiFi : " . $settings['wifi_info'] . "\n";
                if ($settings->has('parking_info')) $context .= "Parking : " . $settings['parking_info'] . "\n";
                if ($settings->has('cantine_info')) $context .= "Cantine : " . $settings['cantine_info'] . "\n";
                if ($settings->has('dress_code')) $context .= "Dress code : " . $settings['dress_code'] . "\n";
            }
        } catch (\Exception $e) {}

        $context .= "\n=== INSTRUCTIONS ===\n";
        $context .= "- Réponds TOUJOURS avec les données ci-dessus. Ne dis jamais que tu n'y as pas accès.\n";
        $context .= "- Pour 'prochaine tâche', donne la première action À FAIRE de la liste.\n";
        $context .= "- Pour 'documents manquants', liste ceux marqués NON FOURNI ou NON SOUMIS.\n";
        $context .= "- Pour 'qui est mon manager/buddy', utilise la section ÉQUIPE.\n";
        $context .= "- Pour les questions hors contexte (salaire, vacances, mutuelle), oriente vers le HRBP ou le manager.\n";
        $context .= "- Sois concis. Utilise des listes à puces. Tutoie le collaborateur.\n";

        return $context;
    }

    private function hasActiveAiPlan(): bool
    {
        $tenant = tenant();
        return Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn($q) => $q->where('addon_type', 'ai'))
            ->exists();
    }

    private function getAiQuota(): ?array
    {
        $tenant = tenant();
        $aiSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn($q) => $q->where('addon_type', 'ai'))
            ->with('plan')
            ->first();

        if (!$aiSub) return null;

        return [
            'bot_limit' => $aiSub->plan->ai_bot_messages ?? 0,
            'model' => $aiSub->plan->ai_model ?? 'claude-haiku-4-5-20251001',
            'plan_name' => $aiSub->plan->nom,
        ];
    }

    /**
     * Get/update auto-recharge settings for AI.
     */
    public function getAutoRechargeConfig(): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) \App\Models\CompanySetting::get('ai_auto_recharge_enabled', false),
            'threshold_percent' => (int) \App\Models\CompanySetting::get('ai_auto_recharge_threshold', 90),
            'recharge_amount_chf' => (float) \App\Models\CompanySetting::get('ai_auto_recharge_amount_chf', 50),
            'recharge_credits' => (int) \App\Models\CompanySetting::get('ai_auto_recharge_credits', 100),
            'max_recharges_per_month' => (int) \App\Models\CompanySetting::get('ai_auto_recharge_max_per_month', 3),
            'recharges_this_month' => (int) \App\Models\CompanySetting::get('ai_auto_recharge_count_' . now()->format('Y_m'), 0),
        ]);
    }

    public function updateAutoRechargeConfig(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'threshold_percent' => 'required|integer|min:50|max:100',
            'recharge_amount_chf' => 'required|numeric|min:10|max:500',
            'recharge_credits' => 'required|integer|min:10|max:5000',
            'max_recharges_per_month' => 'required|integer|min:1|max:10',
        ]);

        \App\Models\CompanySetting::set('ai_auto_recharge_enabled', $request->enabled ? '1' : '0');
        \App\Models\CompanySetting::set('ai_auto_recharge_threshold', (string) $request->threshold_percent);
        \App\Models\CompanySetting::set('ai_auto_recharge_amount_chf', (string) $request->recharge_amount_chf);
        \App\Models\CompanySetting::set('ai_auto_recharge_credits', (string) $request->recharge_credits);
        \App\Models\CompanySetting::set('ai_auto_recharge_max_per_month', (string) $request->max_recharges_per_month);

        return response()->json(['success' => true]);
    }

    /**
     * Get/update spending cap config.
     */
    public function getSpendingCap(): JsonResponse
    {
        $year = now()->year;
        $month = now()->month;
        $monthlySpend = (float) AiUsage::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)->sum('cost_usd');
        $costChf = round($monthlySpend * 0.88, 2);
        $billedChf = round($costChf * 2, 2);

        $planMonthly = $this->getAiPlanMonthlyChf();
        $defaultCap = $planMonthly ?: 29;
        $cap = (float) \App\Models\CompanySetting::get('ai_monthly_spending_cap_chf', $defaultCap);

        return response()->json([
            'spending_cap_chf' => $cap,
            'current_billed_chf' => $billedChf,
            'current_cost_chf' => $costChf,
            'percent_used' => $cap > 0 ? round(($billedChf / $cap) * 100, 1) : 0,
            'plan_monthly_chf' => $planMonthly,
            'max_cap_chf' => $planMonthly * 3,
        ]);
    }

    public function updateSpendingCap(Request $request): JsonResponse
    {
        $request->validate(['spending_cap_chf' => 'required|numeric|min:0']);

        $planMonthly = $this->getAiPlanMonthlyChf();
        $maxCap = $planMonthly * 3;
        $newCap = (float) $request->spending_cap_chf;

        if ($newCap > 0 && $maxCap > 0 && $newCap > $maxCap) {
            return response()->json([
                'error' => "Le plafond ne peut pas dépasser {$maxCap} CHF (3× votre forfait de {$planMonthly} CHF)",
            ], 422);
        }

        \App\Models\CompanySetting::set('ai_monthly_spending_cap_chf', (string) $newCap);
        return response()->json(['success' => true]);
    }

    private function getAiPlanMonthlyChf(): float
    {
        $aiSub = \App\Models\Subscription::where('status', 'active')
            ->orWhere('status', 'trialing')
            ->whereHas('plan', fn($q) => $q->where('addon_type', 'ai'))
            ->with('plan')
            ->first();

        return (float) ($aiSub?->plan?->prix_chf_mensuel ?? 0);
    }

    /**
     * Try to auto-recharge credits when threshold is reached.
     * Creates a Stripe charge and records in ai_recharges table.
     */
    private function tryAutoRecharge(string $type, int $used, int $limit, int $currentExtra): bool
    {
        $enabled = (bool) \App\Models\CompanySetting::get('ai_auto_recharge_enabled', false);
        if (!$enabled) return false;

        $threshold = (int) \App\Models\CompanySetting::get('ai_auto_recharge_threshold', 90);
        $rechargeCredits = (int) \App\Models\CompanySetting::get('ai_auto_recharge_credits', 100);
        $maxPerMonth = (int) \App\Models\CompanySetting::get('ai_auto_recharge_max_per_month', 3);
        $monthKey = 'ai_auto_recharge_count_' . now()->format('Y_m');
        $rechargesThisMonth = (int) \App\Models\CompanySetting::get($monthKey, 0);

        if ($rechargesThisMonth >= $maxPerMonth) {
            Log::info('AI auto-recharge: max recharges reached this month', [
                'count' => $rechargesThisMonth, 'max' => $maxPerMonth,
            ]);
            return false;
        }

        $totalAllowed = $limit + $currentExtra;
        $usagePercent = $totalAllowed > 0 ? ($used / $totalAllowed) * 100 : 100;

        if ($usagePercent < $threshold) return false;

        $rechargeAmountChf = (float) \App\Models\CompanySetting::get('ai_auto_recharge_amount_chf', 50);

        // Create recharge record
        $recharge = \App\Models\AiRecharge::create([
            'amount_chf' => $rechargeAmountChf,
            'credits_added' => $rechargeCredits,
            'trigger' => 'auto',
            'status' => 'pending',
        ]);

        // Charge via Stripe
        $chargeResult = $this->chargeRecharge($recharge, $rechargeAmountChf);

        if (!$chargeResult) {
            return false;
        }

        // Add extra credits
        $key = "ai_extra_{$type}_" . now()->format('Y_m');
        $current = (int) (\App\Models\CompanySetting::where('key', $key)->value('value') ?? 0);
        \App\Models\CompanySetting::updateOrCreate(
            ['key' => $key],
            ['value' => (string)($current + $rechargeCredits)]
        );

        // Increment recharge counter
        \App\Models\CompanySetting::set($monthKey, (string)($rechargesThisMonth + 1));

        Log::info('AI auto-recharge triggered', [
            'type' => $type,
            'credits_added' => $rechargeCredits,
            'amount_chf' => $rechargeAmountChf,
            'recharge_number' => $rechargesThisMonth + 1,
            'stripe_pi' => $recharge->stripe_payment_intent_id,
        ]);

        return true;
    }

    /**
     * Charge a recharge via Stripe.
     */
    private function chargeRecharge(\App\Models\AiRecharge $recharge, float $amountChf): bool
    {
        $tenant = tenant();
        $mode = config('services.stripe.mode') ?: env('STRIPE_MODE', 'live');
        $customerKey = $mode === 'test' ? 'stripe_test_customer_id' : 'stripe_customer_id';
        $customerId = \App\Models\CompanySetting::where('key', $customerKey)->value('value');

        if (!$customerId) {
            $recharge->update(['status' => 'failed', 'error' => 'No Stripe customer']);
            return false;
        }

        try {
            $secret = $mode === 'test'
                ? (config('services.stripe.test_secret') ?: env('STRIPE_TEST_SECRET'))
                : (config('services.stripe.live_secret') ?: env('STRIPE_SECRET'));
            $stripe = new \Stripe\StripeClient($secret);

            $customer = $stripe->customers->retrieve($customerId);
            $pm = $customer->invoice_settings->default_payment_method ?? null;

            if (!$pm) {
                $recharge->update(['status' => 'failed', 'error' => 'No payment method']);
                return false;
            }

            $pi = $stripe->paymentIntents->create([
                'amount' => (int) round($amountChf * 100),
                'currency' => 'chf',
                'customer' => $customerId,
                'payment_method' => $pm,
                'off_session' => true,
                'confirm' => true,
                'description' => "Illizeo - Recharge IA ({$recharge->credits_added} crédits)",
                'metadata' => [
                    'type' => 'ai_recharge',
                    'recharge_id' => $recharge->id,
                    'tenant_id' => $tenant->id,
                ],
            ]);

            if ($pi->status === 'succeeded') {
                $recharge->update([
                    'status' => 'charged',
                    'stripe_payment_intent_id' => $pi->id,
                ]);
                return true;
            }

            $recharge->update([
                'status' => $pi->status === 'processing' ? 'pending' : 'failed',
                'stripe_payment_intent_id' => $pi->id,
                'error' => "Status: {$pi->status}",
            ]);
            return $pi->status === 'processing';

        } catch (\Exception $e) {
            $recharge->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Log::error('AI recharge payment failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Manual recharge (buy credits on demand).
     */
    public function manualRecharge(Request $request): JsonResponse
    {
        $request->validate([
            'amount_chf' => 'required|numeric|min:10|max:500',
        ]);

        $amountChf = (float) $request->amount_chf;

        // Credits: 1 CHF = ~2 credits (based on pricing)
        $credits = (int) ($amountChf * 2);

        $recharge = \App\Models\AiRecharge::create([
            'amount_chf' => $amountChf,
            'credits_added' => $credits,
            'trigger' => 'manual',
            'status' => 'pending',
        ]);

        $success = $this->chargeRecharge($recharge, $amountChf);

        if ($success) {
            // Add credits to current month
            $key = 'ai_extra_bot_message_' . now()->format('Y_m');
            $current = (int) (\App\Models\CompanySetting::where('key', $key)->value('value') ?? 0);
            \App\Models\CompanySetting::updateOrCreate(
                ['key' => $key],
                ['value' => (string)($current + $credits)]
            );

            return response()->json([
                'success' => true,
                'credits_added' => $credits,
                'amount_chf' => $amountChf,
                'recharge_id' => $recharge->id,
            ]);
        }

        return response()->json([
            'error' => $recharge->error ?? 'Payment failed',
        ], 402);
    }

    /**
     * Get recharge history.
     */
    public function getRechargeHistory(): JsonResponse
    {
        $recharges = \App\Models\AiRecharge::orderByDesc('created_at')->take(20)->get();
        return response()->json($recharges);
    }

    private function isStarterPlan(): bool
    {
        $tenant = tenant();
        $aiSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn($q) => $q->where('addon_type', 'ai'))
            ->with('plan')
            ->first();

        if (!$aiSub) return false;

        $slug = $aiSub->plan->slug ?? '';
        return str_contains($slug, 'starter') || str_contains($slug, 'ia_starter');
    }

    private function getExtraCredits(string $type): int
    {
        $key = "ai_extra_{$type}_" . now()->format('Y_m');
        return (int) (\App\Models\CompanySetting::where('key', $key)->value('value') ?? 0);
    }

    private function estimateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = [
            'claude-opus-4-6' => ['input' => 5.0, 'output' => 25.0],
            'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0],
            'claude-haiku-4-5-20251001' => ['input' => 1.0, 'output' => 5.0],
        ];
        $rates = $pricing[$model] ?? $pricing['claude-haiku-4-5-20251001'];
        return ($inputTokens * $rates['input'] / 1_000_000) + ($outputTokens * $rates['output'] / 1_000_000);
    }
}
