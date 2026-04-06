<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\User;
use App\Services\EntraIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EntraIdController extends Controller
{
    // ─── SSO ────────────────────────────────────────────────

    /**
     * Redirect to Microsoft login
     */
    public function ssoRedirect(Request $request): JsonResponse
    {
        $integration = Integration::where('provider', 'entra_id')->first();
        $tenantId = (!empty($integration?->config['tenant_id'])) ? $integration->config['tenant_id'] : env('AZURE_TENANT_ID');
        $clientId = (!empty($integration?->config['client_id'])) ? $integration->config['client_id'] : env('AZURE_CLIENT_ID');
        $clientSecret = (!empty($integration?->config['client_secret'])) ? $integration->config['client_secret'] : env('AZURE_CLIENT_SECRET');

        if (!$tenantId || !$clientId) {
            return response()->json(['error' => 'Entra ID non configuré'], 422);
        }

        $service = new EntraIdService($tenantId, $clientId, $clientSecret);
        $redirectUri = config('app.url') . '/api/v1/auth/microsoft/callback';

        $state = base64_encode(json_encode([
            'tenant_id' => tenant('id'),
        ]));

        $url = $service->buildAuthUrl($redirectUri, $state);
        return response()->json(['redirect_url' => $url]);
    }

    /**
     * Microsoft OAuth callback — create/login user via SSO
     */
    public function ssoCallback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $stateRaw = $request->query('state');
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if (!$code || !$stateRaw) {
            return redirect("{$frontendUrl}?sso=error&reason=missing_code");
        }

        $state = json_decode(base64_decode($stateRaw), true);
        $tenantDbId = $state['tenant_id'] ?? null;

        $tenant = \App\Models\Tenant::find($tenantDbId);
        if (!$tenant) {
            return redirect("{$frontendUrl}?sso=error&reason=tenant_not_found");
        }
        tenancy()->initialize($tenant);

        $integration = Integration::where('provider', 'entra_id')->first();
        $tenantId = (!empty($integration?->config['tenant_id'])) ? $integration->config['tenant_id'] : env('AZURE_TENANT_ID');
        $clientId = (!empty($integration?->config['client_id'])) ? $integration->config['client_id'] : env('AZURE_CLIENT_ID');
        $clientSecret = (!empty($integration?->config['client_secret'])) ? $integration->config['client_secret'] : env('AZURE_CLIENT_SECRET');

        if (!$tenantId || !$clientId) {
            return redirect("{$frontendUrl}?sso=error&reason=not_configured");
        }

        try {
            $service = new EntraIdService($tenantId, $clientId, $clientSecret);
            $redirectUri = config('app.url') . '/api/v1/auth/microsoft/callback';

            $tokens = $service->exchangeCode($code, $redirectUri);
            $profile = $service->getUserProfile($tokens['access_token']);

            $email = $profile['mail'] ?? $profile['userPrincipalName'] ?? '';
            $name = $profile['displayName'] ?? '';

            // Resolve role from AD group mappings
            $resolvedRole = \App\Services\UserProvisioningService::resolveRoleFromAD($tokens['access_token'], $email);

            // Find or create user
            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                ]);
                $user->assignRole($resolvedRole ?? 'onboardee');
            } elseif ($resolvedRole && $user->getRoleNames()->first() !== $resolvedRole) {
                // Update role if AD group mapping changed
                $user->syncRoles([$resolvedRole]);
            }

            // Update name if changed in AD
            if ($user->name !== $name) {
                $user->update(['name' => $name]);
            }

            // Create Sanctum token
            $token = $user->createToken('sso')->plainTextToken;

            return redirect("{$frontendUrl}?sso=success&token={$token}");
        } catch (\Exception $e) {
            return redirect("{$frontendUrl}?sso=error&reason=" . urlencode($e->getMessage()));
        }
    }

    // ─── Integration Config ─────────────────────────────────

    /**
     * Connect Entra ID (save config + test)
     */
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'tenant_id' => 'required|string',
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
        ]);

        try {
            $service = new EntraIdService($request->tenant_id, $request->client_id, $request->client_secret);
            $test = $service->testConnection();

            $integration->update([
                'config' => [
                    'tenant_id' => $request->tenant_id,
                    'client_id' => $request->client_id,
                    'client_secret' => $request->client_secret,
                    'organization' => $test['organization'] ?? '',
                    'domains' => $test['domains'] ?? [],
                    'connected_at' => now()->toISOString(),
                    'sso_enabled' => true,
                    'sync_enabled' => true,
                ],
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Entra ID connecté', 'test' => $test]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Disconnect
     */
    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update(['config' => [], 'actif' => false, 'connecte' => false]);
        return response()->json(['message' => 'Entra ID déconnecté']);
    }

    // ─── Sync ───────────────────────────────────────────────

    // ─── Group Mappings ───────────────────────────────────

    public function listMappings(): JsonResponse
    {
        return response()->json(\App\Models\AdGroupMapping::all());
    }

    public function createMapping(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ad_group_id' => 'required|string|unique:ad_group_mappings,ad_group_id',
            'ad_group_name' => 'required|string',
            'illizeo_role' => 'required|in:super_admin,admin,admin_rh,manager,onboardee',
            'auto_provision' => 'nullable|boolean',
            'auto_deprovision' => 'nullable|boolean',
        ]);

        $mapping = \App\Models\AdGroupMapping::create($validated);
        return response()->json($mapping, 201);
    }

    public function updateMapping(Request $request, \App\Models\AdGroupMapping $adGroupMapping): JsonResponse
    {
        $adGroupMapping->update($request->validate([
            'illizeo_role' => 'nullable|in:super_admin,admin,admin_rh,manager,onboardee',
            'auto_provision' => 'nullable|boolean',
            'auto_deprovision' => 'nullable|boolean',
            'actif' => 'nullable|boolean',
        ]));
        return response()->json($adGroupMapping);
    }

    public function deleteMapping(\App\Models\AdGroupMapping $adGroupMapping): JsonResponse
    {
        $adGroupMapping->delete();
        return response()->json(null, 204);
    }

    public function syncUsers(): JsonResponse
    {
        $result = \App\Services\UserProvisioningService::syncAll();
        return response()->json($result);
    }

    /**
     * List users from Azure AD
     */
    public function listADUsers(Integration $integration): JsonResponse
    {
        $service = EntraIdService::fromIntegration($integration);
        $result = $service->listUsers(200);
        return response()->json($result['users']);
    }

    /**
     * List security groups from Azure AD
     */
    public function listADGroups(Integration $integration): JsonResponse
    {
        $service = EntraIdService::fromIntegration($integration);
        return response()->json($service->listGroups());
    }

    /**
     * Get members of a group
     */
    public function groupMembers(Integration $integration, string $groupId): JsonResponse
    {
        $service = EntraIdService::fromIntegration($integration);
        return response()->json($service->getGroupMembers($groupId));
    }
}
