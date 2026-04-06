<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\UgoSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UgoSignController extends Controller
{
    /**
     * Test connection with API key and save if successful
     */
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
        ]);

        $apiKey = $request->input('api_key');

        try {
            $service = new UgoSignService($apiKey);
            $org = $service->getOrganization();
            $members = $service->getMembers();

            $integration->update([
                'config' => [
                    'api_key' => $apiKey,
                    'organization_name' => $org['name'] ?? $org['organization_name'] ?? '',
                    'organization_id' => $org['id'] ?? '',
                    'plan' => $org['plan'] ?? $org['subscription'] ?? '',
                    'members_count' => is_array($members) ? count($members) : 0,
                    'connected_at' => now()->toISOString(),
                ],
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'UgoSign connecté avec succès',
                'organization' => $org,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Clé API invalide ou erreur de connexion: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Disconnect UgoSign
     */
    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update([
            'config' => [],
            'actif' => false,
            'connecte' => false,
            'derniere_sync' => null,
        ]);

        return response()->json(['message' => 'UgoSign déconnecté']);
    }

    /**
     * List envelopes from UgoSign
     */
    public function envelopes(Request $request, Integration $integration): JsonResponse
    {
        $apiKey = $integration->config['api_key'] ?? null;
        if (!$apiKey) {
            return response()->json(['error' => 'UgoSign non configuré'], 422);
        }

        $service = new UgoSignService($apiKey);
        $envelopes = $service->listEnvelopes($request->query('status'));

        return response()->json($envelopes);
    }
}
