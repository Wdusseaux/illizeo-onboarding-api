<?php

namespace App\Services;

use App\Models\AdGroupMapping;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserProvisioningService
{
    /**
     * Sync all mapped AD groups → create/update users in Illizeo
     */
    public static function syncAll(): array
    {
        $integration = Integration::where('provider', 'entra_id')->where('connecte', true)->first();
        if (!$integration) return ['error' => 'Entra ID non connecté'];

        $service = EntraIdService::fromIntegration($integration);
        $mappings = AdGroupMapping::where('actif', true)->get();

        $created = 0;
        $updated = 0;
        $deprovisioned = 0;

        foreach ($mappings as $mapping) {
            $members = $service->getGroupMembers($mapping->ad_group_id);

            foreach ($members as $adUser) {
                $email = $adUser['mail'] ?? $adUser['userPrincipalName'] ?? null;
                if (!$email) continue;

                $user = User::where('email', $email)->first();

                if (!$user && $mapping->auto_provision) {
                    // Create user
                    $user = User::create([
                        'name' => $adUser['displayName'] ?? $email,
                        'email' => $email,
                        'password' => Hash::make(Str::random(32)),
                    ]);
                    $user->assignRole($mapping->illizeo_role);
                    $created++;
                } elseif ($user) {
                    // Update role if different
                    $currentRole = $user->getRoleNames()->first();
                    if ($currentRole !== $mapping->illizeo_role) {
                        $user->syncRoles([$mapping->illizeo_role]);
                        $updated++;
                    }
                }
            }

            // De-provisioning: find users with this role who are NOT in the AD group
            if ($mapping->auto_deprovision) {
                $adEmails = collect($members)->map(fn ($u) => $u['mail'] ?? $u['userPrincipalName'])->filter()->all();
                $usersToDisable = User::role($mapping->illizeo_role)
                    ->whereNotIn('email', $adEmails)
                    ->get();

                foreach ($usersToDisable as $user) {
                    // Don't delete — just remove the role
                    $user->removeRole($mapping->illizeo_role);
                    $deprovisioned++;
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'deprovisioned' => $deprovisioned,
            'mappings_processed' => $mappings->count(),
        ];
    }

    /**
     * Resolve role for a user during SSO login based on their AD groups
     */
    public static function resolveRoleFromAD(string $accessToken, string $email): ?string
    {
        $integration = Integration::where('provider', 'entra_id')->where('connecte', true)->first();
        if (!$integration) return null;

        try {
            $service = EntraIdService::fromIntegration($integration);

            // Get user's AD ID first
            $token = $service->getAppToken();
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("https://graph.microsoft.com/v1.0/users", [
                    '$filter' => "mail eq '{$email}' or userPrincipalName eq '{$email}'",
                    '$select' => 'id',
                ]);

            $adUserId = $response->json('value.0.id');
            if (!$adUserId) return null;

            // Get user's groups
            $groups = $service->getUserGroups($adUserId);
            $groupIds = collect($groups)->pluck('id')->all();

            // Find matching mapping with highest priority role
            $rolePriority = ['super_admin' => 5, 'admin' => 4, 'admin_rh' => 3, 'manager' => 2, 'onboardee' => 1];
            $bestRole = null;
            $bestPriority = 0;

            $mappings = AdGroupMapping::where('actif', true)->whereIn('ad_group_id', $groupIds)->get();
            foreach ($mappings as $mapping) {
                $priority = $rolePriority[$mapping->illizeo_role] ?? 0;
                if ($priority > $bestPriority) {
                    $bestPriority = $priority;
                    $bestRole = $mapping->illizeo_role;
                }
            }

            return $bestRole;
        } catch (\Exception $e) {
            return null;
        }
    }
}
