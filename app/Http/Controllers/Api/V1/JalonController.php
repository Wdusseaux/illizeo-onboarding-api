<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Jalon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JalonController extends Controller
{
    /**
     * GET /api/v1/jalons?projet_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
        ]);

        return response()->json(
            Jalon::where('projet_id', $data['projet_id'])
                ->orderBy('date')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * POST /api/v1/jalons
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
            'libelle' => 'required|string|max:255',
            'montant' => 'required|numeric|min:0',
            'date' => 'nullable|date',
            'statut' => 'required|in:planned,sent,paid',
        ]);

        $jalon = Jalon::create($data);

        return response()->json($jalon, 201);
    }

    /**
     * PUT /api/v1/jalons/{jalon}
     */
    public function update(Request $request, Jalon $jalon): JsonResponse
    {
        $data = $request->validate([
            'libelle' => 'sometimes|required|string|max:255',
            'montant' => 'sometimes|required|numeric|min:0',
            'date' => 'sometimes|nullable|date',
            'statut' => 'sometimes|required|in:planned,sent,paid',
        ]);

        $jalon->update($data);

        return response()->json($jalon);
    }

    /**
     * DELETE /api/v1/jalons/{jalon}
     */
    public function destroy(Jalon $jalon): JsonResponse
    {
        // Sécurité : on bloque la suppression d'un jalon payé pour traçabilité comptable
        if ($jalon->statut === 'paid') {
            return response()->json([
                'error' => 'Impossible de supprimer un jalon payé (traçabilité comptable)',
            ], 422);
        }

        $jalon->delete();

        return response()->json(null, 204);
    }
}
