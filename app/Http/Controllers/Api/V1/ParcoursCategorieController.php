<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ParcoursCategorie;
use Illuminate\Http\JsonResponse;

class ParcoursCategorieController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ParcoursCategorie::withCount('parcours')->get());
    }

    public function show(ParcoursCategorie $parcoursCategorie): JsonResponse
    {
        return response()->json($parcoursCategorie->load('parcours'));
    }
}
