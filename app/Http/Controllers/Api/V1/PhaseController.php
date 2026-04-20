<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Phase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Phase::with('parcours');

        if ($request->has('parcours_id')) {
            $query->whereHas('parcours', fn ($q) => $q->where('parcours.id', $request->parcours_id));
        }

        return response()->json($query->orderBy('ordre')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'delai_debut' => 'nullable|string|regex:/^J[+-]\d{1,3}$/',
            'delai_fin' => 'nullable|string|regex:/^J[+-]\d{1,3}$/',
            'couleur' => 'nullable|string|max:10',
            'icone' => 'nullable|string',
            'actions_defaut' => 'nullable|integer',
            'ordre' => 'nullable|integer',
            'active' => 'nullable|boolean',
            'parcours_ids' => 'nullable|array',
            'parcours_ids.*' => 'exists:parcours,id',
        ]);

        $parcoursIds = $validated['parcours_ids'] ?? [];
        unset($validated['parcours_ids']);

        $phase = Phase::create($validated);

        if (!empty($parcoursIds)) {
            $phase->parcours()->sync($parcoursIds);
        }

        return response()->json($phase->load('parcours'), 201);
    }

    public function show(Phase $phase): JsonResponse
    {
        return response()->json($phase->load(['parcours', 'actions.actionType']));
    }

    public function update(Request $request, Phase $phase): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'delai_debut' => 'nullable|string|regex:/^J[+-]\d{1,3}$/',
            'delai_fin' => 'nullable|string|regex:/^J[+-]\d{1,3}$/',
            'couleur' => 'nullable|string|max:10',
            'icone' => 'nullable|string',
            'actions_defaut' => 'nullable|integer',
            'ordre' => 'nullable|integer',
            'active' => 'nullable|boolean',
            'parcours_ids' => 'nullable|array',
            'parcours_ids.*' => 'exists:parcours,id',
        ]);

        $parcoursIds = $validated['parcours_ids'] ?? null;
        unset($validated['parcours_ids']);

        $phase->update($validated);

        if ($parcoursIds !== null) {
            $phase->parcours()->sync($parcoursIds);
        }

        return response()->json($phase->load('parcours'));
    }

    public function destroy(Phase $phase): JsonResponse
    {
        $phase->delete();
        return response()->json(null, 204);
    }
}
