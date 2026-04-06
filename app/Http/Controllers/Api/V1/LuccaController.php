<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\LuccaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LuccaController extends Controller
{
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'subdomain' => 'required|string',
            'api_key' => 'required|string',
        ]);

        try {
            $service = new LuccaService($request->subdomain, $request->api_key);
            $test = $service->testConnection();

            $integration->update([
                'config' => [
                    'subdomain' => $request->subdomain,
                    'api_key' => $request->api_key,
                    'connected_at' => now()->toISOString(),
                ],
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Lucca connecté', 'test' => $test]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update(['config' => [], 'actif' => false, 'connecte' => false, 'derniere_sync' => null]);
        return response()->json(['message' => 'Lucca déconnecté']);
    }

    public function users(Request $request, Integration $integration): JsonResponse
    {
        $service = LuccaService::fromIntegration($integration);
        return response()->json($service->listUsers($request->input('limit', 100), $request->input('offset', 0)));
    }

    public function orgStructure(Integration $integration): JsonResponse
    {
        $service = LuccaService::fromIntegration($integration);
        return response()->json([
            'departments' => $service->listDepartments(),
            'establishments' => $service->listEstablishments(),
        ]);
    }
}
