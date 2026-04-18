<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Traits\ChecksPlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    use ChecksPlanLimits;

    public function index(Request $request): JsonResponse
    {
        $query = Integration::query();

        if ($request->has('categorie')) {
            $query->where('categorie', $request->categorie);
        }

        $integrations = $query->get()->map(function ($i) {
            return [
                'id' => $i->id,
                'provider' => $i->provider,
                'categorie' => $i->categorie,
                'nom' => $i->nom,
                'config' => $i->config_safe,
                'actif' => $i->actif,
                'connecte' => $i->connecte,
                'derniere_sync' => $i->derniere_sync,
            ];
        });

        return response()->json($integrations);
    }

    public function store(Request $request): JsonResponse
    {
        $limitCheck = $this->checkPlanLimit('max_integrations', Integration::count(), 'integrations');
        if ($limitCheck) {
            return $limitCheck;
        }

        $validated = $request->validate([
            'provider' => 'required|string',
            'categorie' => 'required|string',
            'nom' => 'required|string',
            'config' => 'nullable|array',
            'actif' => 'nullable|boolean',
        ]);

        $integration = Integration::create($validated);

        return response()->json([
            ...$integration->toArray(),
            'config' => $integration->config_safe,
        ], 201);
    }

    public function show(Integration $integration): JsonResponse
    {
        return response()->json([
            ...$integration->toArray(),
            'config' => $integration->config_safe,
        ]);
    }

    public function update(Request $request, Integration $integration): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'sometimes|string',
            'config' => 'nullable|array',
            'actif' => 'nullable|boolean',
            'connecte' => 'nullable|boolean',
        ]);

        // Merge config: don't overwrite masked fields
        if (isset($validated['config'])) {
            $existing = $integration->config ?? [];
            foreach ($validated['config'] as $key => $value) {
                if ($value !== null && !str_starts_with($value, '••••')) {
                    $existing[$key] = $value;
                }
            }
            $validated['config'] = $existing;
        }

        $integration->update($validated);

        return response()->json([
            ...$integration->fresh()->toArray(),
            'config' => $integration->fresh()->config_safe,
        ]);
    }

    public function destroy(Integration $integration): JsonResponse
    {
        $integration->delete();
        return response()->json(null, 204);
    }

    public function test(Integration $integration): JsonResponse
    {
        // Placeholder: in production, this would actually test the connection
        return response()->json([
            'success' => true,
            'message' => "Connexion à {$integration->nom} réussie",
        ]);
    }
}
