<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CooptationValidated;
use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\Cooptation;
use App\Models\CooptationCampaign;
use App\Models\CooptationPoint;
use App\Models\CooptationSetting;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CooptationController extends Controller
{
    public function index(): JsonResponse
    {
        $cooptations = Cooptation::with(['referrerUser', 'collaborateur'])
            ->orderByDesc('date_cooptation')
            ->get()
            ->map(fn ($c) => array_merge($c->toArray(), [
                'is_validable' => $c->is_validable,
                'jours_restants' => $c->jours_restants,
            ]));

        return response()->json($cooptations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'referrer_name' => 'required|string|max:255',
            'referrer_email' => 'required|email|max:255',
            'referrer_user_id' => 'nullable|exists:users,id',
            'candidate_name' => 'required|string|max:255',
            'candidate_email' => 'required|email|max:255',
            'candidate_poste' => 'nullable|string|max:255',
            'collaborateur_id' => 'nullable|exists:collaborateurs,id',
            'date_cooptation' => 'required|date',
            'date_embauche' => 'nullable|date',
            'mois_requis' => 'nullable|integer|min:1',
            'statut' => 'nullable|string|in:en_attente,embauche,valide,recompense_versee,refuse',
            'type_recompense' => 'nullable|string|in:prime,cadeau',
            'montant_recompense' => 'nullable|numeric|min:0',
            'description_recompense' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'linkedin_url' => 'nullable|string|url|max:255',
            'telephone' => 'nullable|string|max:30',
        ]);

        // Apply default settings if not provided
        $settings = CooptationSetting::first();
        if ($settings) {
            $validated['mois_requis'] = $validated['mois_requis'] ?? $settings->mois_requis_defaut;
            $validated['type_recompense'] = $validated['type_recompense'] ?? $settings->type_recompense_defaut;
            $validated['montant_recompense'] = $validated['montant_recompense'] ?? $settings->montant_defaut;
        }

        $cooptation = Cooptation::create($validated);

        // Auto-link referrer user by email
        $referrerUser = User::where('email', $validated['referrer_email'])->first();
        if ($referrerUser) {
            $cooptation->update(['referrer_user_id' => $referrerUser->id]);
        }

        return response()->json($cooptation->load(['referrerUser', 'collaborateur']), 201);
    }

    public function update(Request $request, Cooptation $cooptation): JsonResponse
    {
        $validated = $request->validate([
            'referrer_name' => 'sometimes|string|max:255',
            'referrer_email' => 'sometimes|email|max:255',
            'referrer_user_id' => 'nullable|exists:users,id',
            'candidate_name' => 'sometimes|string|max:255',
            'candidate_email' => 'sometimes|email|max:255',
            'candidate_poste' => 'nullable|string|max:255',
            'collaborateur_id' => 'nullable|exists:collaborateurs,id',
            'date_cooptation' => 'sometimes|date',
            'date_embauche' => 'nullable|date',
            'mois_requis' => 'nullable|integer|min:1',
            'statut' => 'nullable|string|in:en_attente,embauche,valide,recompense_versee,refuse',
            'type_recompense' => 'nullable|string|in:prime,cadeau',
            'montant_recompense' => 'nullable|numeric|min:0',
            'description_recompense' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $cooptation->update($validated);

        return response()->json($cooptation->load(['referrerUser', 'collaborateur']));
    }

    public function destroy(Cooptation $cooptation): JsonResponse
    {
        $cooptation->delete();

        return response()->json(null, 204);
    }

    public function markHired(Request $request, Cooptation $cooptation): JsonResponse
    {
        $validated = $request->validate([
            'date_embauche' => 'required|date',
            'collaborateur_id' => 'nullable|exists:collaborateurs,id',
        ]);

        $dateEmbauche = Carbon::parse($validated['date_embauche']);

        $cooptation->update([
            'statut' => 'embauche',
            'date_embauche' => $dateEmbauche,
            'date_validation' => $dateEmbauche->copy()->addMonths($cooptation->mois_requis),
            'collaborateur_id' => $validated['collaborateur_id'] ?? $cooptation->collaborateur_id,
        ]);

        // Auto-create collaborateur from cooptation
        $existingCollab = Collaborateur::where('email', $cooptation->candidate_email)->first();
        if (!$existingCollab) {
            $names = explode(' ', $cooptation->candidate_name, 2);
            $collab = Collaborateur::create([
                'prenom' => $names[0] ?? '',
                'nom' => $names[1] ?? '',
                'email' => $cooptation->candidate_email,
                'poste' => $cooptation->candidate_poste ?? '',
                'date_debut' => $validated['date_embauche'],
                'status' => 'en_cours',
                'progression' => 0,
            ]);
            $cooptation->update(['collaborateur_id' => $collab->id]);
        }

        $this->awardPoints($cooptation, 10, 'embauche');

        // Notify referrer
        if ($cooptation->referrer_user_id) {
            NotificationService::send($cooptation->referrer_user_id, 'cooptation',
                'Candidat embauche !',
                "Votre candidat {$cooptation->candidate_name} a ete embauche.",
                'user-check', '#4CAF50');
        }

        return response()->json($cooptation->load(['referrerUser', 'collaborateur']));
    }

    public function validate(Cooptation $cooptation): JsonResponse
    {
        if (!$cooptation->is_validable) {
            return response()->json([
                'message' => 'Cette cooptation ne peut pas encore être validée.',
            ], 422);
        }

        $cooptation->update(['statut' => 'valide']);

        $this->awardPoints($cooptation, 25, 'validation');

        // Fire workflow event
        CooptationValidated::dispatch($cooptation->id, $cooptation->referrer_name, $cooptation->candidate_name);

        // Notify referrer + auto-award badge
        if ($cooptation->referrer_user_id) {
            NotificationService::send($cooptation->referrer_user_id, 'cooptation',
                'Cooptation validée !',
                "Votre cooptation de {$cooptation->candidate_name} est validée. Votre récompense sera versée.",
                'check-circle', '#4CAF50');
            \App\Services\BadgeAutoAwardService::checkAndAward($cooptation->referrer_user_id, 'cooptation');
        }

        return response()->json($cooptation->load(['referrerUser', 'collaborateur']));
    }

    public function markRewarded(Request $request, Cooptation $cooptation): JsonResponse
    {
        $validated = $request->validate([
            'type_recompense' => 'nullable|string|in:prime,cadeau',
            'montant_recompense' => 'nullable|numeric|min:0',
            'description_recompense' => 'nullable|string|max:255',
        ]);

        $cooptation->update(array_merge($validated, [
            'recompense_versee' => true,
            'date_versement' => Carbon::today(),
            'statut' => 'recompense_versee',
        ]));

        $this->awardPoints($cooptation, 15, 'bonus');

        // Notify referrer
        if ($cooptation->referrer_user_id) {
            NotificationService::send($cooptation->referrer_user_id, 'cooptation',
                'Récompense versée !',
                "Votre récompense pour la cooptation de {$cooptation->candidate_name} a été versée.",
                'gift', '#7B5EA7');
        }

        return response()->json($cooptation->load(['referrerUser', 'collaborateur']));
    }

    public function refuse(Cooptation $cooptation): JsonResponse
    {
        $cooptation->update(['statut' => 'refuse']);

        // Notify referrer
        if ($cooptation->referrer_user_id) {
            NotificationService::send($cooptation->referrer_user_id, 'cooptation',
                'Cooptation refusée',
                "Votre recommandation de {$cooptation->candidate_name} n'a pas été retenue.",
                'x-circle', '#E53935');
        }

        return response()->json($cooptation->load(['referrerUser', 'collaborateur']));
    }

    public function stats(): JsonResponse
    {
        $cooptations = Cooptation::all();
        $total = $cooptations->count();

        // Advanced stats
        $avgDaysToHire = Cooptation::whereNotNull('date_embauche')
            ->selectRaw('AVG(JULIANDAY(date_embauche) - JULIANDAY(date_cooptation)) as avg_days')
            ->value('avg_days');

        $conversionRate = $total > 0
            ? round(Cooptation::whereIn('statut', ['embauche', 'valide', 'recompense_versee'])->count() / $total * 100, 1)
            : 0;

        return response()->json([
            'total' => $total,
            'en_attente' => $cooptations->where('statut', 'en_attente')->count(),
            'embauche' => $cooptations->where('statut', 'embauche')->count(),
            'valide' => $cooptations->where('statut', 'valide')->count(),
            'recompense_versee' => $cooptations->where('statut', 'recompense_versee')->count(),
            'refuse' => $cooptations->where('statut', 'refuse')->count(),
            'total_recompenses_versees' => $cooptations->where('recompense_versee', true)->sum('montant_recompense'),
            'recompenses_en_attente' => $cooptations->whereIn('statut', ['embauche', 'valide'])->sum('montant_recompense'),
            'avg_days_to_hire' => round($avgDaysToHire ?? 0),
            'conversion_rate' => $conversionRate,
        ]);
    }

    public function getSettings(): JsonResponse
    {
        $settings = CooptationSetting::first();

        if (!$settings) {
            $settings = CooptationSetting::create([
                'mois_requis_defaut' => 6,
                'montant_defaut' => 500.00,
                'type_recompense_defaut' => 'prime',
                'actif' => true,
            ]);
        }

        return response()->json($settings);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mois_requis_defaut' => 'sometimes|integer|min:1',
            'montant_defaut' => 'sometimes|numeric|min:0',
            'type_recompense_defaut' => 'sometimes|string|in:prime,cadeau',
            'description_recompense_defaut' => 'nullable|string|max:255',
            'actif' => 'sometimes|boolean',
        ]);

        $settings = CooptationSetting::first();

        if (!$settings) {
            $settings = CooptationSetting::create($validated);
        } else {
            $settings->update($validated);
        }

        return response()->json($settings);
    }

    // ── CV Upload ────────────────────────────────────────────

    public function uploadCV(Request $request, Cooptation $cooptation): JsonResponse
    {
        $request->validate(['cv' => 'required|file|max:5120|mimes:pdf,doc,docx']);

        $file = $request->file('cv');
        $path = $file->store('cooptation-cv', 'local');

        $cooptation->update([
            'cv_path' => $path,
            'cv_original_name' => $file->getClientOriginalName(),
        ]);

        return response()->json(['message' => 'CV uploadé', 'filename' => $file->getClientOriginalName()]);
    }

    // ── Campaigns ───────────────────────────────────────────

    public function listCampaigns(): JsonResponse
    {
        return response()->json(
            CooptationCampaign::withCount('cooptations')
                ->orderByRaw("CASE WHEN statut='active' THEN 0 ELSE 1 END")
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'titre' => 'required|string',
            'description' => 'nullable|string',
            'departement' => 'nullable|string',
            'site' => 'nullable|string',
            'type_contrat' => 'nullable|string',
            'type_recompense' => 'nullable|in:prime,cadeau',
            'montant_recompense' => 'nullable|numeric',
            'description_recompense' => 'nullable|string',
            'mois_requis' => 'nullable|integer',
            'date_limite' => 'nullable|date',
            'nombre_postes' => 'nullable|integer',
            'priorite' => 'nullable|in:basse,normale,haute,urgente',
        ]);

        return response()->json(CooptationCampaign::create($data), 201);
    }

    public function updateCampaign(Request $request, CooptationCampaign $campaign): JsonResponse
    {
        $campaign->update($request->validate([
            'titre' => 'nullable|string',
            'description' => 'nullable|string',
            'departement' => 'nullable|string',
            'site' => 'nullable|string',
            'type_contrat' => 'nullable|string',
            'type_recompense' => 'nullable|in:prime,cadeau',
            'montant_recompense' => 'nullable|numeric',
            'description_recompense' => 'nullable|string',
            'mois_requis' => 'nullable|integer',
            'statut' => 'nullable|in:active,pourvue,fermee',
            'date_limite' => 'nullable|date',
            'nombre_postes' => 'nullable|integer',
            'priorite' => 'nullable|in:basse,normale,haute,urgente',
        ]));

        return response()->json($campaign);
    }

    public function deleteCampaign(CooptationCampaign $campaign): JsonResponse
    {
        $campaign->delete();

        return response()->json(null, 204);
    }

    // ── Leaderboard ─────────────────────────────────────────

    public function leaderboard(): JsonResponse
    {
        $leaders = CooptationPoint::select('referrer_email', 'referrer_name')
            ->selectRaw('SUM(points) as total_points')
            ->selectRaw('COUNT(DISTINCT cooptation_id) as total_cooptations')
            ->groupBy('referrer_email', 'referrer_name')
            ->orderByDesc('total_points')
            ->limit(20)
            ->get();

        return response()->json($leaders);
    }

    // ── Private helpers ─────────────────────────────────────

    private function awardPoints(Cooptation $cooptation, int $points, string $motif): void
    {
        CooptationPoint::create([
            'user_id' => $cooptation->referrer_user_id,
            'referrer_email' => $cooptation->referrer_email,
            'referrer_name' => $cooptation->referrer_name,
            'cooptation_id' => $cooptation->id,
            'points' => $points,
            'motif' => $motif,
        ]);
    }
}
