<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContratController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Contrat::all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'type' => 'required|string',
            'juridiction' => 'required|string',
            'variables' => 'nullable|integer',
            'actif' => 'nullable|boolean',
            'fichier' => 'nullable|string',
        ]);

        $contrat = Contrat::create($validated);
        return response()->json($contrat, 201);
    }

    public function show(Contrat $contrat): JsonResponse
    {
        return response()->json($contrat);
    }

    public function update(Request $request, Contrat $contrat): JsonResponse
    {
        $contrat->update($request->validate([
            'nom' => 'sometimes|string|max:255',
            'type' => 'sometimes|string',
            'juridiction' => 'sometimes|string',
            'variables' => 'nullable|integer',
            'actif' => 'nullable|boolean',
            'fichier' => 'nullable|string',
        ]));

        return response()->json($contrat);
    }

    public function destroy(Contrat $contrat): JsonResponse
    {
        $contrat->delete();
        return response()->json(null, 204);
    }
}
