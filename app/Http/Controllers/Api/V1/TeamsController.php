<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\TeamsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamsController extends Controller
{
    /**
     * Connect via Webhook URL (simple mode)
     */
    public function connectWebhook(Request $request, Integration $integration): JsonResponse
    {
        $request->validate(['webhook_url' => 'required|url']);

        try {
            $service = new TeamsService($request->webhook_url);
            $success = $service->testWebhook();

            if (!$success) {
                return response()->json(['success' => false, 'message' => 'Le webhook n\'a pas répondu correctement'], 422);
            }

            $config = $integration->config ?? [];
            $config['webhook_url'] = $request->webhook_url;
            $config['connected_at'] = now()->toISOString();
            $config['mode'] = 'webhook';

            $integration->update([
                'config' => $config,
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Teams connecté — message de test envoyé dans le canal']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Connect via Azure AD (Graph API mode for meetings)
     */
    public function connectGraph(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'tenant_id' => 'required|string',
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
        ]);

        try {
            $token = TeamsService::getAppToken($request->tenant_id, $request->client_id, $request->client_secret);

            $config = $integration->config ?? [];
            $config['tenant_id'] = $request->tenant_id;
            $config['client_id'] = $request->client_id;
            $config['client_secret'] = $request->client_secret;
            $config['access_token'] = $token;
            $config['graph_connected'] = true;
            $config['graph_connected_at'] = now()->toISOString();

            $integration->update([
                'config' => $config,
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Microsoft Graph API connecté']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Disconnect
     */
    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update(['config' => [], 'actif' => false, 'connecte' => false, 'derniere_sync' => null]);
        return response()->json(['message' => 'Teams déconnecté']);
    }

    /**
     * Send a test notification
     */
    public function testNotification(Request $request, Integration $integration): JsonResponse
    {
        $service = TeamsService::fromIntegration($integration);
        $success = $service->sendWebhookCard(
            '🔔 Test de notification',
            'Ceci est un test envoyé depuis Illizeo.',
            '#C2185B',
            ['Envoyé par' => $request->user()->name ?? 'Admin', 'Date' => now()->format('d/m/Y H:i')]
        );

        return response()->json(['success' => $success]);
    }

    /**
     * Create a meeting
     */
    public function createMeeting(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'organizer_email' => 'required|email',
            'subject' => 'required|string',
            'start' => 'required|string',
            'end' => 'required|string',
            'attendees' => 'nullable|array',
            'attendees.*' => 'email',
        ]);

        $service = TeamsService::fromIntegration($integration);
        $meeting = $service->createMeeting(
            $request->organizer_email,
            $request->subject,
            $request->start,
            $request->end,
            $request->attendees ?? [],
            $request->input('content')
        );

        return response()->json($meeting, 201);
    }
}
