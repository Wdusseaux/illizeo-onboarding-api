<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Action;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Action::with(['actionType', 'phase', 'parcours']);

        if ($request->has('parcours_id')) {
            $query->where('parcours_id', $request->parcours_id);
        }
        if ($request->has('phase_id')) {
            $query->where('phase_id', $request->phase_id);
        }
        if ($request->has('type')) {
            $query->whereHas('actionType', fn ($q) => $q->where('slug', $request->type));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'action_type_id' => 'required|exists:action_types,id',
            'phase_id' => 'nullable|exists:phases,id',
            'parcours_id' => 'nullable|exists:parcours,id',
            'delai_relatif' => 'nullable|string',
            'obligatoire' => 'nullable|boolean',
            'description' => 'nullable|string',
            'lien_externe' => 'nullable|url',
            'duree_estimee' => 'nullable|string',
            'pieces_requises' => 'nullable|array',
            'assignation_mode' => 'nullable|in:tous,individuel,groupe,site,departement,contrat,parcours,phase',
            'assignation_valeurs' => 'nullable|array',
        ]);

        $action = Action::create($validated);
        return response()->json($action->load(['actionType', 'phase']), 201);
    }

    public function show(Action $action): JsonResponse
    {
        return response()->json($action->load(['actionType', 'phase', 'parcours']));
    }

    public function update(Request $request, Action $action): JsonResponse
    {
        $validated = $request->validate([
            'titre' => 'sometimes|string|max:255',
            'action_type_id' => 'sometimes|exists:action_types,id',
            'phase_id' => 'nullable|exists:phases,id',
            'parcours_id' => 'nullable|exists:parcours,id',
            'delai_relatif' => 'nullable|string',
            'obligatoire' => 'nullable|boolean',
            'description' => 'nullable|string',
            'lien_externe' => 'nullable|url',
            'duree_estimee' => 'nullable|string',
            'pieces_requises' => 'nullable|array',
            'assignation_mode' => 'nullable|in:tous,individuel,groupe,site,departement,contrat,parcours,phase',
            'assignation_valeurs' => 'nullable|array',
        ]);

        $action->update($validated);
        return response()->json($action->load(['actionType', 'phase']));
    }

    public function destroy(Action $action): JsonResponse
    {
        $action->delete();
        return response()->json(null, 204);
    }
}
