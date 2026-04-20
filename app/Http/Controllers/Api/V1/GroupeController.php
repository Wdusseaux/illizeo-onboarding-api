<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Groupe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Groupe::with('collaborateurs')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'description' => 'nullable|string',
            'couleur' => 'nullable|string|max:10',
            'critere_type' => 'nullable|in:site,departement,contrat',
            'critere_valeur' => 'nullable|string',
            'translations' => 'nullable|array',
            'collaborateur_ids' => 'nullable|array',
            'collaborateur_ids.*' => 'exists:collaborateurs,id',
        ]);

        $collabIds = $validated['collaborateur_ids'] ?? [];
        unset($validated['collaborateur_ids']);

        $groupe = Groupe::create($validated);

        if (!empty($collabIds)) {
            $groupe->collaborateurs()->sync($collabIds);
        }

        return response()->json($groupe->load('collaborateurs'), 201);
    }

    public function show(Groupe $groupe): JsonResponse
    {
        return response()->json($groupe->load('collaborateurs'));
    }

    public function update(Request $request, Groupe $groupe): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'couleur' => 'nullable|string|max:10',
            'critere_type' => 'nullable|in:site,departement,contrat',
            'critere_valeur' => 'nullable|string',
            'translations' => 'nullable|array',
            'collaborateur_ids' => 'nullable|array',
            'collaborateur_ids.*' => 'exists:collaborateurs,id',
        ]);

        $collabIds = $validated['collaborateur_ids'] ?? null;
        unset($validated['collaborateur_ids']);

        $groupe->update($validated);

        if ($collabIds !== null) {
            $groupe->collaborateurs()->sync($collabIds);
        }

        return response()->json($groupe->load('collaborateurs'));
    }

    public function destroy(Groupe $groupe): JsonResponse
    {
        $groupe->delete();
        return response()->json(null, 204);
    }
}
