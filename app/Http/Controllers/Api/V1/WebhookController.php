<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    /**
     * List all webhooks for the tenant.
     */
    public function index(): JsonResponse
    {
        $webhooks = Webhook::with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($wh) {
                return [
                    'id' => $wh->id,
                    'url' => $wh->url,
                    'events' => $wh->events,
                    'active' => $wh->active,
                    'last_triggered_at' => $wh->last_triggered_at,
                    'failure_count' => $wh->failure_count,
                    'user' => $wh->user ? ['id' => $wh->user->id, 'name' => $wh->user->name] : null,
                    'created_at' => $wh->created_at,
                    'updated_at' => $wh->updated_at,
                ];
            });

        return response()->json($webhooks);
    }

    /**
     * Create a new webhook with auto-generated HMAC secret.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'string|max:100',
        ]);

        $secret = 'whsec_' . bin2hex(random_bytes(24));

        $webhook = Webhook::create([
            'user_id' => auth()->id(),
            'url' => $validated['url'],
            'events' => $validated['events'],
            'secret' => $secret,
            'active' => true,
        ]);

        AuditLog::log(
            action: 'webhook_created',
            entityType: 'webhook',
            entityId: $webhook->id,
            entityLabel: $validated['url'],
            description: "Webhook créé pour {$validated['url']}",
        );

        return response()->json([
            'webhook' => $webhook->makeVisible('secret'),
            'message' => 'Conservez le secret en lieu sûr. Il ne sera plus affiché.',
        ], 201);
    }

    /**
     * Update a webhook (url, events, active status).
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);

        $validated = $request->validate([
            'url' => 'sometimes|url|max:2048',
            'events' => 'sometimes|array|min:1',
            'events.*' => 'string|max:100',
            'active' => 'sometimes|boolean',
        ]);

        $oldValues = $webhook->only(array_keys($validated));
        $webhook->update($validated);

        AuditLog::log(
            action: 'webhook_updated',
            entityType: 'webhook',
            entityId: $webhook->id,
            entityLabel: $webhook->url,
            description: "Webhook #{$webhook->id} mis à jour",
            oldValues: $oldValues,
            newValues: $validated,
        );

        return response()->json($webhook);
    }

    /**
     * Delete a webhook and its logs.
     */
    public function destroy(int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);
        $url = $webhook->url;

        $webhook->delete();

        AuditLog::log(
            action: 'webhook_deleted',
            entityType: 'webhook',
            entityId: $id,
            entityLabel: $url,
            description: "Webhook \"{$url}\" supprimé",
        );

        return response()->json(['message' => 'Webhook supprimé.']);
    }

    /**
     * Send a test ping event to a webhook.
     */
    public function test(int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);

        $log = $webhook->dispatch('ping', [
            'message' => 'Test webhook from Illizeo',
            'timestamp' => now()->toIso8601String(),
        ]);

        if (!$log) {
            return response()->json(['message' => 'Le webhook est désactivé.'], 422);
        }

        return response()->json([
            'message' => 'Ping envoyé.',
            'log' => $log,
        ]);
    }
}
