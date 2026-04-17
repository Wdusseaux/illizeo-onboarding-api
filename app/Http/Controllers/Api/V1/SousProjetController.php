<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SousProjet;
use App\Models\Tache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SousProjetController extends Controller
{
    /**
     * GET /api/v1/sous-projets?projet_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
        ]);

        $sousProjets = SousProjet::where('projet_id', $data['projet_id'])
            ->orderBy('id')
            ->get();

        return response()->json($sousProjets);
    }

    /**
     * POST /api/v1/sous-projets
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
            'nom' => 'required|string|max:255',
            'est_facturable' => 'boolean',
            // heures non éditable depuis ce module (alimenté par Présences)
        ]);

        $sousProjet = SousProjet::create($data);

        return response()->json($sousProjet, 201);
    }

    /**
     * PUT /api/v1/sous-projets/{sousProjet}
     */
    public function update(Request $request, SousProjet $sousProjet): JsonResponse
    {
        $data = $request->validate([
            'nom' => 'sometimes|required|string|max:255',
            'est_facturable' => 'sometimes|boolean',
            // heures toujours read-only ici
        ]);

        $sousProjet->update($data);

        return response()->json($sousProjet);
    }

    /**
     * DELETE /api/v1/sous-projets/{sousProjet}
     * Bloqué si tâches liées OU heures saisies, sauf si un reassign_to_id est fourni
     * (auquel cas les tâches sont réassignées avant suppression).
     */
    public function destroy(Request $request, SousProjet $sousProjet): JsonResponse
    {
        $data = $request->validate([
            'reassign_to_id' => 'nullable|integer|exists:sous_projets,id',
        ]);

        $tasksCount = $sousProjet->taches()->count();
        $hasHours = $sousProjet->heures > 0;

        // Cas C/D — heures saisies : blocage absolu (cross-module, à gérer dans Présences)
        if ($hasHours) {
            return response()->json([
                'error' => 'Suppression bloquée',
                'reasons' => ['has_hours' => true, 'hours' => $sousProjet->heures],
                'message' => 'Des heures ont été saisies dans Présences pour ce sous-projet. Réaffectez-les d\'abord.',
            ], 422);
        }

        // Cas B — tâches liées sans reassign : blocage
        if ($tasksCount > 0 && !array_key_exists('reassign_to_id', $data)) {
            return response()->json([
                'error' => 'Suppression bloquée',
                'reasons' => ['has_tasks' => true, 'tasks_count' => $tasksCount],
                'message' => 'Réassignez les tâches à un autre sous-projet (paramètre reassign_to_id) ou détachez-les (reassign_to_id: null).',
            ], 422);
        }

        // Sécurité : reassign_to_id doit appartenir au même projet
        if (!empty($data['reassign_to_id'])) {
            $target = SousProjet::find($data['reassign_to_id']);
            if (!$target || $target->projet_id !== $sousProjet->projet_id) {
                return response()->json(['error' => 'Le sous-projet cible doit appartenir au même projet'], 422);
            }
        }

        // Cas A (libre) ou cas B avec reassign valide
        DB::transaction(function () use ($sousProjet, $data) {
            if ($sousProjet->taches()->exists()) {
                $sousProjet->taches()->update([
                    'sous_projet_id' => $data['reassign_to_id'] ?? null,
                ]);
            }
            $sousProjet->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/sous-projets/{sousProjet}/reassigner-taches
     * Réassigne explicitement toutes les tâches d'un sous-projet vers un autre (ou null = détacher).
     * Utilisé en amont d'un destroy si on veut séparer les deux étapes côté client.
     */
    public function reassignerTaches(Request $request, SousProjet $sousProjet): JsonResponse
    {
        $data = $request->validate([
            'target_id' => 'nullable|integer|exists:sous_projets,id',
        ]);

        if (!empty($data['target_id'])) {
            $target = SousProjet::find($data['target_id']);
            if (!$target || $target->projet_id !== $sousProjet->projet_id) {
                return response()->json(['error' => 'Le sous-projet cible doit appartenir au même projet'], 422);
            }
        }

        $count = $sousProjet->taches()->count();
        $sousProjet->taches()->update(['sous_projet_id' => $data['target_id'] ?? null]);

        return response()->json([
            'reassigned_count' => $count,
            'target_id' => $data['target_id'] ?? null,
        ]);
    }
}
