<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanyBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyBlockController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(CompanyBlock::orderBy('ordre')->get());
    }

    public function activeBlocks(): JsonResponse
    {
        return response()->json(CompanyBlock::where('actif', true)->orderBy('ordre')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'titre' => 'nullable|string',
            'contenu' => 'nullable|string',
            'data' => 'nullable|array',
            'ordre' => 'nullable|integer',
            'actif' => 'nullable|boolean',
        ]);

        $block = CompanyBlock::create($validated);
        return response()->json($block, 201);
    }

    public function update(Request $request, CompanyBlock $companyBlock): JsonResponse
    {
        $companyBlock->update($request->validate([
            'type' => 'sometimes|string',
            'titre' => 'nullable|string',
            'contenu' => 'nullable|string',
            'data' => 'nullable|array',
            'ordre' => 'nullable|integer',
            'actif' => 'nullable|boolean',
        ]));
        return response()->json($companyBlock);
    }

    public function destroy(CompanyBlock $companyBlock): JsonResponse
    {
        $companyBlock->delete();
        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate(['blocks' => 'required|array', 'blocks.*.id' => 'required|exists:company_blocks,id', 'blocks.*.ordre' => 'required|integer']);
        foreach ($request->blocks as $item) {
            CompanyBlock::where('id', $item['id'])->update(['ordre' => $item['ordre']]);
        }
        return response()->json(['ok' => true]);
    }
}
