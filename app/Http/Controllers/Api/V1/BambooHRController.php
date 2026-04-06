<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\BambooHRService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BambooHRController extends Controller
{
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate(['company_domain' => 'required|string', 'api_key' => 'required|string']);
        try {
            $service = new BambooHRService($request->company_domain, $request->api_key);
            $test = $service->testConnection();
            $integration->update([
                'config' => ['company_domain' => $request->company_domain, 'api_key' => $request->api_key, 'total_employees' => $test['total_employees'], 'connected_at' => now()->toISOString()],
                'actif' => true, 'connecte' => true, 'derniere_sync' => now(),
            ]);
            return response()->json(['success' => true, 'message' => 'BambooHR connecté', 'total_employees' => $test['total_employees']]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update(['config' => [], 'actif' => false, 'connecte' => false, 'derniere_sync' => null]);
        return response()->json(['message' => 'BambooHR déconnecté']);
    }

    public function employees(Integration $integration): JsonResponse
    {
        return response()->json(BambooHRService::fromIntegration($integration)->listEmployees());
    }
}
