<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\NewCollaborateur;
use App\Events\ParcoursCompleted;
use App\Events\ParcoursCreated;
use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\Parcours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollaborateurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Collaborateur::with(['parcours', 'groupes']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('site')) {
            $query->where('site', $request->site);
        }
        if ($request->has('departement')) {
            $query->where('departement', $request->departement);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prenom' => 'required|string|max:255',
            'nom' => 'required|string|max:255',
            'email' => 'required|email|unique:collaborateurs,email',
            'poste' => 'nullable|string',
            'site' => 'nullable|string',
            'departement' => 'nullable|string',
            'date_debut' => 'nullable|date',
            'parcours_id' => 'nullable|exists:parcours,id',
        ]);

        $validated['initials'] = strtoupper(mb_substr($validated['prenom'], 0, 1) . mb_substr($validated['nom'], 0, 1));
        $collaborateur = Collaborateur::create($validated);

        NewCollaborateur::dispatch(
            $collaborateur->id,
            "{$collaborateur->prenom} {$collaborateur->nom}"
        );

        // Fire ParcoursCreated if a parcours was assigned
        if (!empty($validated['parcours_id'])) {
            $parcours = Parcours::find($validated['parcours_id']);
            if ($parcours) {
                ParcoursCreated::dispatch($collaborateur->id, $parcours->nom);
            }
        }

        return response()->json($collaborateur, 201);
    }

    public function show(Collaborateur $collaborateur): JsonResponse
    {
        return response()->json(
            $collaborateur->load(['parcours.categorie', 'groupes', 'documents.categorie'])
        );
    }

    public function update(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        $validated = $request->validate([
            'prenom' => 'sometimes|string|max:255',
            'nom' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:collaborateurs,email,' . $collaborateur->id,
            'poste' => 'nullable|string',
            'site' => 'nullable|string',
            'departement' => 'nullable|string',
            'date_debut' => 'nullable|date',
            'phase' => 'nullable|string',
            'progression' => 'nullable|integer|min:0|max:100',
            'status' => 'nullable|in:en_cours,en_retard,termine',
            'parcours_id' => 'nullable|exists:parcours,id',
        ]);

        $previousProgression = $collaborateur->progression;
        $previousParcoursId = $collaborateur->parcours_id;
        $collaborateur->update($validated);

        // Fire ParcoursCompleted when progression reaches 100%
        if (isset($validated['progression']) && (int) $validated['progression'] === 100 && (int) $previousProgression !== 100) {
            $parcoursName = $collaborateur->parcours?->nom ?? 'Parcours';
            ParcoursCompleted::dispatch($collaborateur->id, $parcoursName);
        }

        // Fire ParcoursCreated when parcours_id is newly assigned
        if (isset($validated['parcours_id']) && $validated['parcours_id'] != $previousParcoursId) {
            $parcours = Parcours::find($validated['parcours_id']);
            if ($parcours) {
                ParcoursCreated::dispatch($collaborateur->id, $parcours->nom);
            }
        }

        return response()->json($collaborateur);
    }

    public function destroy(Collaborateur $collaborateur): JsonResponse
    {
        $collaborateur->delete();
        return response()->json(null, 204);
    }
}
