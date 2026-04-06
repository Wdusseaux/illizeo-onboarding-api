<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\PersonioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonioController extends Controller
{
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
        ]);

        try {
            $service = new PersonioService($request->client_id, $request->client_secret);
            $test = $service->testConnection();

            $integration->update([
                'config' => [
                    'client_id' => $request->client_id,
                    'client_secret' => $request->client_secret,
                    'total_employees' => $test['total'] ?? 0,
                    'connected_at' => now()->toISOString(),
                ],
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Personio connecté', 'test' => $test]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update(['config' => [], 'actif' => false, 'connecte' => false, 'derniere_sync' => null]);
        return response()->json(['message' => 'Personio déconnecté']);
    }

    public function employees(Request $request, Integration $integration): JsonResponse
    {
        $service = PersonioService::fromIntegration($integration);
        return response()->json($service->listEmployees($request->input('limit', 100), $request->input('offset', 0)));
    }
}
