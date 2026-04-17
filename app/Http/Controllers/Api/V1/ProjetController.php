<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Projet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjetController extends Controller
{
    /**
     * GET /api/v1/projets
     * Liste tous les projets avec champs minimaux pour la sidebar.
     * Pas de pagination — convention Illizeo.
     */
    public function index(): JsonResponse
    {
        $projets = Projet::with([
            // Field selection pour ne charger que ce qu'il faut côté sidebar
            'membres:id',
        ])
            ->orderByRaw("CASE statut WHEN 'actif' THEN 1 WHEN 'brouillon' THEN 2 WHEN 'archive' THEN 3 END")
            ->orderBy('nom')
            ->get();

        return response()->json($projets);
    }

    /**
     * GET /api/v1/projets/{projet}
     * Charge l'arbre complet du projet pour l'affichage détaillé.
     * Eager loading hybride : champs select sur les User pour limiter le payload.
     */
    public function show(Projet $projet): JsonResponse
    {
        $projet->load([
            'membres:id,prenom,nom,email',
            'sousProjets',
            'taches.lead:id,prenom,nom',
            'taches.collaborateurs:id,prenom,nom',
            'taches.sousTaches',
            'taches.commentaires.user:id,prenom,nom',
            'jalons',
            'lignesCouts',
            'tauxHoraires',
        ]);

        return response()->json($projet);
    }

    /**
     * POST /api/v1/projets
     * Création d'un projet. Validation inline.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:projets,code',
            'statut' => 'required|in:actif,archive,brouillon',
            'couleur' => 'nullable|string|max:7',
            'client_type' => 'required|in:internal,external',
            'client' => 'nullable|string|max:255',
            'contact_prenom' => 'nullable|string|max:255',
            'contact_nom' => 'nullable|string|max:255',
            'societe' => 'nullable|string|max:255',
            'adresse_client' => 'nullable|string|max:255',
            'email_client' => 'nullable|email|max:255',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
            'description' => 'nullable|string',
            'devise' => 'nullable|string|size:3',
            'est_facturable' => 'boolean',
            'type_budget' => 'required|in:none,hours,cost',
            'valeur_budget' => 'nullable|numeric|min:0',
            'prix_vente' => 'nullable|numeric|min:0',
            'member_roles' => 'nullable|array',
        ]);

        $projet = Projet::create($data);
        $projet->load(['membres:id,prenom,nom', 'sousProjets']);

        return response()->json($projet, 201);
    }

    /**
     * PUT /api/v1/projets/{projet}
     * Mise à jour. Tous les champs sont optionnels (partial update accepté).
     */
    public function update(Request $request, Projet $projet): JsonResponse
    {
        $data = $request->validate([
            'nom' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:projets,code,' . $projet->id,
            'statut' => 'sometimes|required|in:actif,archive,brouillon',
            'couleur' => 'sometimes|nullable|string|max:7',
            'client_type' => 'sometimes|required|in:internal,external',
            'client' => 'sometimes|nullable|string|max:255',
            'contact_prenom' => 'sometimes|nullable|string|max:255',
            'contact_nom' => 'sometimes|nullable|string|max:255',
            'societe' => 'sometimes|nullable|string|max:255',
            'adresse_client' => 'sometimes|nullable|string|max:255',
            'email_client' => 'sometimes|nullable|email|max:255',
            'date_debut' => 'sometimes|nullable|date',
            'date_fin' => 'sometimes|nullable|date|after_or_equal:date_debut',
            'description' => 'sometimes|nullable|string',
            'devise' => 'sometimes|nullable|string|size:3',
            'est_facturable' => 'sometimes|boolean',
            'type_budget' => 'sometimes|required|in:none,hours,cost',
            'valeur_budget' => 'sometimes|nullable|numeric|min:0',
            'prix_vente' => 'sometimes|nullable|numeric|min:0',
            'member_roles' => 'sometimes|nullable|array',
        ]);

        $projet->update($data);
        $projet->load(['membres:id,prenom,nom']);

        return response()->json($projet);
    }

    /**
     * DELETE /api/v1/projets/{projet}
     * Suppression définitive avec contrôle des contraintes côté serveur.
     * (La modale frontend bloque déjà côté UX, mais on revérifie ici par sécurité.)
     */
    public function destroy(Request $request, Projet $projet): JsonResponse
    {
        $hasMembers = $projet->membres()->exists();
        $hasPaidMilestones = $projet->jalons()->where('statut', 'paid')->exists();
        $isActive = $projet->statut === 'actif';

        // Blocage si projet actif + (membres OU jalons payés), sauf override explicite
        $allowOverride = $request->boolean('override_paid_milestones');

        if ($isActive && ($hasMembers || $hasPaidMilestones)) {
            return response()->json([
                'error' => 'Suppression bloquée',
                'reasons' => [
                    'has_members' => $hasMembers,
                    'has_paid_milestones' => $hasPaidMilestones,
                ],
                'message' => 'Désactivez le projet avant de le supprimer.',
            ], 422);
        }

        // Si archivé + jalons payés, exiger override explicite (dérogation)
        if (!$isActive && $hasPaidMilestones && !$allowOverride) {
            return response()->json([
                'error' => 'Dérogation requise',
                'message' => 'Ce projet contient des jalons payés. Confirmez avec override_paid_milestones=true.',
            ], 422);
        }

        $projet->delete();

        return response()->json(null, 204);
    }

    /**
     * PATCH /api/v1/projets/{projet}/desactiver
     * Passe le statut à "archive".
     */
    public function desactiver(Projet $projet): JsonResponse
    {
        $projet->update(['statut' => 'archive']);

        return response()->json($projet);
    }

    /**
     * PATCH /api/v1/projets/{projet}/reactiver
     * Repasse le statut à "actif".
     */
    public function reactiver(Projet $projet): JsonResponse
    {
        $projet->update(['statut' => 'actif']);

        return response()->json($projet);
    }

    /**
     * POST /api/v1/projets/{projet}/membres
     * Ajoute un membre au projet (idempotent).
     */
    public function ajouterMembre(Request $request, Projet $projet): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $projet->membres()->syncWithoutDetaching([$data['user_id']]);
        $projet->load('membres:id,prenom,nom');

        return response()->json($projet, 201);
    }

    /**
     * DELETE /api/v1/projets/{projet}/membres/{user}
     * Retire un membre du projet.
     */
    public function retirerMembre(Projet $projet, User $user): JsonResponse
    {
        $projet->membres()->detach($user->id);

        // Optionnel : nettoyer le member_roles JSON
        $roles = $projet->member_roles ?? [];
        unset($roles[$user->id]);
        $projet->update(['member_roles' => $roles ?: null]);

        $projet->load('membres:id,prenom,nom');

        return response()->json($projet);
    }

    /**
     * PATCH /api/v1/projets/{projet}/membres/{user}/role
     * Met à jour le rôle métier (Chef de projet, Développeur, etc.) d'un membre.
     */
    public function changerRoleMembre(Request $request, Projet $projet, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => 'required|in:owner,editor,viewer,consultant',
        ]);

        if (!$projet->membres()->where('users.id', $user->id)->exists()) {
            return response()->json(['error' => "L'utilisateur n'est pas membre du projet"], 422);
        }

        $roles = $projet->member_roles ?? [];
        $roles[$user->id] = $data['role'];
        $projet->update(['member_roles' => $roles]);

        return response()->json($projet);
    }
}
