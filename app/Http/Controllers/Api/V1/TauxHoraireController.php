<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TauxHoraire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TauxHoraireController extends Controller
{
    /**
     * GET /api/v1/taux-horaires?projet_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
        ]);

        return response()->json(
            TauxHoraire::where('projet_id', $data['projet_id'])->orderBy('id')->get()
        );
    }

    /**
     * POST /api/v1/taux-horaires
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets,id',
            'role_libelle' => 'required|string|max:255',
            'taux' => 'required|numeric|min:0',
        ]);

        $taux = TauxHoraire::create($data);

        return response()->json($taux, 201);
    }

    /**
     * PUT /api/v1/taux-horaires/{tauxHoraire}
     */
    public function update(Request $request, TauxHoraire $tauxHoraire): JsonResponse
    {
        $data = $request->validate([
            'role_libelle' => 'sometimes|required|string|max:255',
            'taux' => 'sometimes|required|numeric|min:0',
        ]);

        $tauxHoraire->update($data);

        return response()->json($tauxHoraire);
    }

    /**
     * DELETE /api/v1/taux-horaires/{tauxHoraire}
     */
    public function destroy(TauxHoraire $tauxHoraire): JsonResponse
    {
        $tauxHoraire->delete();

        return response()->json(null, 204);
    }
}
