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
        ]);

        $groupe = Groupe::create($validated);
        return response()->json($groupe, 201);
    }

    public function show(Groupe $groupe): JsonResponse
    {
        return response()->json($groupe->load('collaborateurs'));
    }

    public function update(Request $request, Groupe $groupe): JsonResponse
    {
        $groupe->update($request->validate([
            'nom' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'couleur' => 'nullable|string|max:10',
            'critere_type' => 'nullable|in:site,departement,contrat',
            'critere_valeur' => 'nullable|string',
        ]));

        return response()->json($groupe);
    }

    public function destroy(Groupe $groupe): JsonResponse
    {
        $groupe->delete();
        return response()->json(null, 204);
    }
}
