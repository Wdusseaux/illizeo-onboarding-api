<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Parcours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParcoursController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Parcours::with(['categorie', 'phases']);

        if ($request->has('categorie')) {
            $query->whereHas('categorie', fn ($q) => $q->where('slug', $request->categorie));
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'categorie_id' => 'required|exists:parcours_categories,id',
            'status' => 'nullable|in:actif,brouillon,archive',
        ]);

        $parcours = Parcours::create($validated);
        return response()->json($parcours->load('categorie'), 201);
    }

    public function show(Parcours $parcour): JsonResponse
    {
        return response()->json(
            $parcour->load(['categorie', 'phases', 'actions.actionType', 'collaborateurs'])
        );
    }

    public function update(Request $request, Parcours $parcour): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'categorie_id' => 'sometimes|exists:parcours_categories,id',
            'status' => 'nullable|in:actif,brouillon,archive',
        ]);

        $parcour->update($validated);
        return response()->json($parcour->load('categorie'));
    }

    public function destroy(Parcours $parcour): JsonResponse
    {
        $parcour->delete();
        return response()->json(null, 204);
    }

    public function duplicate(Parcours $parcour): JsonResponse
    {
        $copy = $parcour->replicate();
        $copy->nom = $parcour->nom . ' (copie)';
        $copy->status = 'brouillon';
        $copy->save();

        return response()->json($copy, 201);
    }
}
