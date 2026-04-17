<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SousTache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SousTacheController extends Controller
{
    /**
     * GET /api/v1/sous-taches?tache_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tache_id' => 'required|exists:taches,id',
        ]);

        return response()->json(
            SousTache::where('tache_id', $data['tache_id'])->orderBy('id')->get()
        );
    }

    /**
     * POST /api/v1/sous-taches
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tache_id' => 'required|exists:taches,id',
            'titre' => 'required|string|max:255',
            'est_terminee' => 'boolean',
        ]);

        $sousTache = SousTache::create($data);

        return response()->json($sousTache, 201);
    }

    /**
     * PUT /api/v1/sous-taches/{sousTache}
     */
    public function update(Request $request, SousTache $sousTache): JsonResponse
    {
        $data = $request->validate([
            'titre' => 'sometimes|required|string|max:255',
            'est_terminee' => 'sometimes|boolean',
        ]);

        $sousTache->update($data);

        return response()->json($sousTache);
    }

    /**
     * DELETE /api/v1/sous-taches/{sousTache}
     */
    public function destroy(SousTache $sousTache): JsonResponse
    {
        $sousTache->delete();

        return response()->json(null, 204);
    }
}
