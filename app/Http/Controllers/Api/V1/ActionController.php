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

    private function resolveActionTypeId(Request $request): void
    {
        if (!$request->action_type_id && $request->action_type_slug) {
            $type = \App\Models\ActionType::where('slug', $request->action_type_slug)->first();
            if ($type) $request->merge(['action_type_id' => $type->id]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $this->resolveActionTypeId($request);
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
            'xp' => 'nullable|integer|min:0|max:1000',
            'heure_default' => 'nullable|string|regex:/^\d{2}:\d{2}$/',
            'accompagnant_role' => 'nullable|string|in:buddy,manager,hrbp,it,admin_rh',
            'pieces_requises' => 'nullable|array',
            'assignation_mode' => 'nullable|in:tous,individuel,groupe,site,departement,contrat,parcours,phase',
            'assignation_valeurs' => 'nullable|array',
            'options' => 'nullable|array',
            'translations' => 'nullable|array',
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
        $this->resolveActionTypeId($request);
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
            'xp' => 'nullable|integer|min:0|max:1000',
            'heure_default' => 'nullable|string|regex:/^\d{2}:\d{2}$/',
            'accompagnant_role' => 'nullable|string|in:buddy,manager,hrbp,it,admin_rh',
            'pieces_requises' => 'nullable|array',
            'assignation_mode' => 'nullable|in:tous,individuel,groupe,site,departement,contrat,parcours,phase',
            'assignation_valeurs' => 'nullable|array',
            'options' => 'nullable|array',
            'translations' => 'nullable|array',
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
