<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TacheController extends Controller
{
    /**
     * GET /api/v1/taches?projet_id=X
     * Liste des tâches d'un projet (filtre obligatoire pour éviter de tout retourner).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
        ]);

        $taches = Tache::with([
            'lead:id,prenom,nom',
            'collaborateurs:id,prenom,nom',
            'sousTaches',
            'commentaires.user:id,prenom,nom',
        ])
            ->where('projet_id', $data['projet_id'])
            ->orderBy('created_at')
            ->get();

        return response()->json($taches);
    }

    /**
     * GET /api/v1/taches/{tache}
     */
    public function show(Tache $tache): JsonResponse
    {
        $tache->load([
            'lead:id,prenom,nom',
            'collaborateurs:id,prenom,nom',
            'sousTaches',
            'commentaires.user:id,prenom,nom',
        ]);

        return response()->json($tache);
    }

    /**
     * POST /api/v1/taches
     * Création d'une tâche. Le projet_id est obligatoire dans le payload.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
            'sous_projet_id' => 'nullable|exists:sous_projets,id',
            'titre' => 'required|string|max:255',
            'statut' => 'required|in:todo,in_progress,done,cancelled',
            'priorite' => 'required|in:urgent,high,normal,low',
            'lead_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'collaborateurs' => 'nullable|array',
            'collaborateurs.*' => 'integer|exists:users,id|different:lead_id',
        ]);

        $collaborateurs = $data['collaborateurs'] ?? [];
        unset($data['collaborateurs']);

        $tache = Tache::create($data);

        if (!empty($collaborateurs)) {
            // Sécurité : retirer le lead s'il s'est glissé dans les collabs
            $collaborateurs = array_values(array_diff($collaborateurs, [$tache->lead_id]));
            $tache->collaborateurs()->sync($collaborateurs);
        }

        $tache->load([
            'lead:id,prenom,nom',
            'collaborateurs:id,prenom,nom',
            'sousTaches',
            'commentaires.user:id,prenom,nom',
        ]);

        return response()->json($tache, 201);
    }

    /**
     * PUT /api/v1/taches/{tache}
     * Mise à jour de tâche. Les champs `lead_id` et `collaborateurs` sont gérés
     * de manière à toujours garder l'invariant : lead_id ∉ collaborateurs.
     */
    public function update(Request $request, Tache $tache): JsonResponse
    {
        $data = $request->validate([
            'sous_projet_id' => 'sometimes|nullable|exists:sous_projets,id',
            'titre' => 'sometimes|required|string|max:255',
            'statut' => 'sometimes|required|in:todo,in_progress,done,cancelled',
            'priorite' => 'sometimes|required|in:urgent,high,normal,low',
            'lead_id' => 'sometimes|nullable|exists:users,id',
            'due_date' => 'sometimes|nullable|date',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string|max:50',
            'collaborateurs' => 'sometimes|array',
            'collaborateurs.*' => 'integer|exists:users,id',
        ]);

        $collaborateurs = $data['collaborateurs'] ?? null;
        unset($data['collaborateurs']);

        $tache->update($data);

        if ($collaborateurs !== null) {
            // Maintenir l'invariant : lead_id ∉ collaborateurs
            $newLeadId = $tache->fresh()->lead_id;
            $collaborateurs = array_values(array_diff($collaborateurs, [$newLeadId]));
            $tache->collaborateurs()->sync($collaborateurs);
        } elseif (array_key_exists('lead_id', $data)) {
            // Si on change le lead sans toucher aux collabs, on retire le nouveau lead
            // de la liste des collabs s'il y était.
            $newLeadId = $data['lead_id'];
            if ($newLeadId) {
                $tache->collaborateurs()->detach($newLeadId);
            }
        }

        $tache->load([
            'lead:id,prenom,nom',
            'collaborateurs:id,prenom,nom',
            'sousTaches',
            'commentaires.user:id,prenom,nom',
        ]);

        return response()->json($tache);
    }

    /**
     * DELETE /api/v1/taches/{tache}
     */
    public function destroy(Tache $tache): JsonResponse
    {
        $tache->delete();

        return response()->json(null, 204);
    }
}
