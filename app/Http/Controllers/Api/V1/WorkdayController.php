<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\WorkdayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkdayController extends Controller
{
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'host' => 'required|string',
            'tenant' => 'required|string',
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
            'refresh_token' => 'required|string',
        ]);
        try {
            $token = WorkdayService::authenticate($request->host, $request->tenant, $request->client_id, $request->client_secret, $request->refresh_token);
            $service = new WorkdayService($request->host, $request->tenant, $token);
            $test = $service->testConnection();
            $integration->update([
                'config' => [
                    'host' => $request->host, 'tenant' => $request->tenant,
                    'client_id' => $request->client_id, 'client_secret' => $request->client_secret,
                    'refresh_token' => $request->refresh_token, 'access_token' => $token,
                    'total_workers' => $test['total'] ?? 0, 'connected_at' => now()->toISOString(),
                ],
                'actif' => true, 'connecte' => true, 'derniere_sync' => now(),
            ]);
            return response()->json(['success' => true, 'message' => 'Workday connecté']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update(['config' => [], 'actif' => false, 'connecte' => false, 'derniere_sync' => null]);
        return response()->json(['message' => 'Workday déconnecté']);
    }

    public function workers(Request $request, Integration $integration): JsonResponse
    {
        return response()->json(WorkdayService::fromIntegration($integration)->listWorkers($request->input('limit', 100)));
    }
}
