<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LigneCout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LigneCoutController extends Controller
{
    /**
     * GET /api/v1/lignes-couts?projet_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
        ]);

        return response()->json(
            LigneCout::where('projet_id', $data['projet_id'])->orderBy('id')->get()
        );
    }

    /**
     * POST /api/v1/lignes-couts
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
            'libelle' => 'required|string|max:255',
            'montant' => 'required|numeric|min:0',
        ]);

        $ligne = LigneCout::create($data);

        return response()->json($ligne, 201);
    }

    /**
     * PUT /api/v1/lignes-couts/{ligneCout}
     */
    public function update(Request $request, LigneCout $ligneCout): JsonResponse
    {
        $data = $request->validate([
            'libelle' => 'sometimes|required|string|max:255',
            'montant' => 'sometimes|required|numeric|min:0',
        ]);

        $ligneCout->update($data);

        return response()->json($ligneCout);
    }

    /**
     * DELETE /api/v1/lignes-couts/{ligneCout}
     */
    public function destroy(LigneCout $ligneCout): JsonResponse
    {
        $ligneCout->delete();

        return response()->json(null, 204);
    }
}
