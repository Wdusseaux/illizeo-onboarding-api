<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\TeamtailorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamtailorController extends Controller
{
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate(['api_key' => 'required|string']);
        try {
            $service = new TeamtailorService($request->api_key);
            $test = $service->testConnection();
            $integration->update([
                'config' => ['api_key' => $request->api_key, 'company_name' => $test['company_name'] ?? '', 'connected_at' => now()->toISOString()],
                'actif' => true, 'connecte' => true, 'derniere_sync' => now(),
            ]);
            return response()->json(['success' => true, 'message' => 'Teamtailor connecté', 'company' => $test['company_name']]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update(['config' => [], 'actif' => false, 'connecte' => false, 'derniere_sync' => null]);
        return response()->json(['message' => 'Teamtailor déconnecté']);
    }

    public function hiredCandidates(Integration $integration): JsonResponse
    {
        return response()->json(TeamtailorService::fromIntegration($integration)->getHiredCandidates());
    }
}
