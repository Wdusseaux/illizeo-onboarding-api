<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollaborateurFieldConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(CollaborateurFieldConfig::orderBy('section')->orderBy('ordre')->get());
    }

    public function update(Request $request, CollaborateurFieldConfig $collaborateurFieldConfig): JsonResponse
    {
        $collaborateurFieldConfig->update($request->validate([
            'actif' => 'nullable|boolean',
            'obligatoire' => 'nullable|boolean',
            'label' => 'nullable|string',
            'label_en' => 'nullable|string',
            'field_type' => 'nullable|in:text,number,date,list,boolean',
            'list_values' => 'nullable|array',
            'ordre' => 'nullable|integer',
        ]));
        return response()->json($collaborateurFieldConfig);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field_key' => 'required|string|unique:collaborateur_field_config,field_key',
            'label' => 'required|string',
            'label_en' => 'nullable|string',
            'section' => 'required|in:personal,contract,org',
            'field_type' => 'nullable|in:text,number,date,list,boolean',
            'list_values' => 'nullable|array',
            'actif' => 'nullable|boolean',
            'obligatoire' => 'nullable|boolean',
        ]);

        $validated['field_key'] = 'custom_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($validated['field_key']));
        $validated['ordre'] = CollaborateurFieldConfig::where('section', $validated['section'])->max('ordre') + 1;

        $config = CollaborateurFieldConfig::create($validated);
        return response()->json($config, 201);
    }

    public function destroy(CollaborateurFieldConfig $collaborateurFieldConfig): JsonResponse
    {
        // Only allow deleting custom fields
        if (!str_starts_with($collaborateurFieldConfig->field_key, 'custom_')) {
            return response()->json(['error' => 'Les champs système ne peuvent pas être supprimés'], 422);
        }
        $collaborateurFieldConfig->delete();
        return response()->json(null, 204);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate(['fields' => 'required|array']);
        foreach ($request->fields as $item) {
            CollaborateurFieldConfig::where('id', $item['id'])->update([
                'actif' => $item['actif'] ?? true,
                'obligatoire' => $item['obligatoire'] ?? false,
            ]);
        }
        return response()->json(['ok' => true]);
    }
}
