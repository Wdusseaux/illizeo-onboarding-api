<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    /**
     * List all API keys for the tenant (hides key_hash, shows prefix).
     */
    public function index(): JsonResponse
    {
        $keys = ApiKey::with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($key) {
                return [
                    'id' => $key->id,
                    'name' => $key->name,
                    'key_prefix' => $key->key_prefix,
                    'scopes' => $key->scopes,
                    'user' => $key->user ? ['id' => $key->user->id, 'name' => $key->user->name] : null,
                    'last_used_at' => $key->last_used_at,
                    'expires_at' => $key->expires_at,
                    'revoked_at' => $key->revoked_at,
                    'is_active' => $key->isActive(),
                    'created_at' => $key->created_at,
                ];
            });

        return response()->json($keys);
    }

    /**
     * Create a new API key. Returns the full plain key ONCE.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string|max:100',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $result = ApiKey::generateKey(
            name: $validated['name'],
            scopes: $validated['scopes'] ?? null,
            expiresAt: $validated['expires_at'] ?? null,
        );

        AuditLog::log(
            action: 'api_key_created',
            entityType: 'api_key',
            entityId: $result['api_key']->id,
            entityLabel: $validated['name'],
            description: "Clé API \"{$validated['name']}\" créée",
        );

        return response()->json([
            'api_key' => [
                'id' => $result['api_key']->id,
                'name' => $result['api_key']->name,
                'key_prefix' => $result['api_key']->key_prefix,
                'scopes' => $result['api_key']->scopes,
                'expires_at' => $result['api_key']->expires_at,
                'created_at' => $result['api_key']->created_at,
            ],
            'plain_key' => $result['plain_key'],
            'message' => 'Conservez cette clé en lieu sûr. Elle ne sera plus affichée.',
        ], 201);
    }

    /**
     * Revoke an API key (soft-disable by setting revoked_at).
     */
    public function revoke(int $id): JsonResponse
    {
        $key = ApiKey::findOrFail($id);

        if ($key->revoked_at) {
            return response()->json(['message' => 'Cette clé est déjà révoquée.'], 422);
        }

        $key->update(['revoked_at' => now()]);

        AuditLog::log(
            action: 'api_key_revoked',
            entityType: 'api_key',
            entityId: $key->id,
            entityLabel: $key->name,
            description: "Clé API \"{$key->name}\" révoquée",
        );

        return response()->json(['message' => 'Clé API révoquée avec succès.']);
    }

    /**
     * Permanently delete an API key.
     */
    public function destroy(int $id): JsonResponse
    {
        $key = ApiKey::findOrFail($id);
        $name = $key->name;

        $key->delete();

        AuditLog::log(
            action: 'api_key_deleted',
            entityType: 'api_key',
            entityId: $id,
            entityLabel: $name,
            description: "Clé API \"{$name}\" supprimée définitivement",
        );

        return response()->json(['message' => 'Clé API supprimée.']);
    }
}
