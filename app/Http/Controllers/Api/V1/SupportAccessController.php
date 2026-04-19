<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupportAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportAccessController extends Controller
{
    /**
     * List all support accesses (active and expired).
     */
    public function index(): JsonResponse
    {
        $accesses = SupportAccess::with('grantedBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'email' => $a->email,
                'granted_by' => $a->grantedBy?->name,
                'allowed_modules' => $a->allowed_modules,
                'reason' => $a->reason,
                'expires_at' => $a->expires_at,
                'revoked_at' => $a->revoked_at,
                'last_used_at' => $a->last_used_at,
                'is_active' => $a->isActive(),
                'created_at' => $a->created_at,
            ]);

        return response()->json($accesses);
    }

    /**
     * Grant support access.
     */
    public function grant(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'allowed_modules' => 'nullable|array',
            'reason' => 'nullable|string|max:500',
            'duration_hours' => 'required|integer|min:1|max:720', // max 30 days
        ]);

        $token = SupportAccess::generateToken();

        $access = SupportAccess::create([
            'email' => $request->email,
            'access_token' => hash('sha256', $token),
            'granted_by' => auth()->id(),
            'allowed_modules' => $request->allowed_modules,
            'reason' => $request->reason,
            'expires_at' => now()->addHours($request->duration_hours),
        ]);

        // Audit log
        AuditLog::log(
            'support_access_granted',
            'support_access',
            $access->id,
            $request->email,
            "Accès support accordé à {$request->email} pour {$request->duration_hours}h"
        );

        // Build the access URL
        $tenantId = tenant('id');
        $accessUrl = "https://onboarding-illizeo.jcloud-ver-jpc.ik-server.com/{$tenantId}/support-login?token={$token}";

        // Send email to support
        try {
            $grantedBy = auth()->user()->name;
            $expiresAt = $access->expires_at->format('d/m/Y H:i');
            $modules = $access->allowed_modules ? implode(', ', $access->allowed_modules) : 'Tous les modules';

            \Illuminate\Support\Facades\Mail::html(
                $this->buildAccessEmail($tenantId, $grantedBy, $request->email, $accessUrl, $expiresAt, $modules, $request->reason),
                function ($message) use ($request, $tenantId) {
                    $message->to($request->email)
                            ->subject("Accès support Illizeo — {$tenantId}");
                }
            );
        } catch (\Exception $e) {
            \Log::warning("Failed to send support access email: " . $e->getMessage());
        }

        return response()->json([
            'message' => "Accès accordé à {$request->email} jusqu'au " . $access->expires_at->format('d/m/Y H:i'),
            'access' => [
                'id' => $access->id,
                'email' => $access->email,
                'expires_at' => $access->expires_at,
                'access_url' => $accessUrl,
            ],
        ], 201);
    }

    /**
     * Revoke a support access.
     */
    public function revoke(int $id): JsonResponse
    {
        $access = SupportAccess::findOrFail($id);
        $access->update(['revoked_at' => now()]);

        AuditLog::log(
            'support_access_revoked',
            'support_access',
            $access->id,
            $access->email,
            "Accès support révoqué pour {$access->email}"
        );

        return response()->json(['message' => "Accès révoqué pour {$access->email}"]);
    }

    /**
     * Login with support access token (no account needed).
     */
    public function loginWithToken(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $tokenHash = hash('sha256', $request->token);
        $access = SupportAccess::where('access_token', $tokenHash)->first();

        if (!$access) {
            return response()->json(['error' => 'Token invalide'], 401);
        }

        if (!$access->isActive()) {
            return response()->json(['error' => 'Accès expiré ou révoqué'], 403);
        }

        // Update last used
        $access->update(['last_used_at' => now()]);

        // Create a temporary user-like token for API access
        // Find or create a virtual support user
        $supportUser = User::firstOrCreate(
            ['email' => 'support-' . $access->id . '@illizeo.internal'],
            [
                'name' => 'Support Illizeo (' . $access->email . ')',
                'password' => bcrypt(\Illuminate\Support\Str::random(32)),
            ]
        );

        // Assign limited role
        if (method_exists($supportUser, 'assignRole')) {
            try { $supportUser->assignRole('admin'); } catch (\Exception $e) {}
        }

        $token = $supportUser->createToken('support-access', ['*'], $access->expires_at)->plainTextToken;

        AuditLog::log(
            'support_login',
            'support_access',
            $access->id,
            $access->email,
            "Connexion support via token par {$access->email}"
        );

        return response()->json([
            'user' => [
                'id' => $supportUser->id,
                'name' => $supportUser->name,
                'email' => $access->email,
                'roles' => ['support'],
                'permissions' => [],
                'collaborateur_id' => null,
                'is_support' => true,
                'support_modules' => $access->allowed_modules,
                'support_expires_at' => $access->expires_at,
            ],
            'token' => $token,
        ]);
    }

    private function buildAccessEmail(string $tenantId, string $grantedBy, string $email, string $url, string $expiresAt, string $modules, ?string $reason): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <span style="font-size: 24px; font-weight: 700; color: #E91E63;">ILLIZEO</span>
    </div>
    <h2 style="margin: 0 0 8px;">Accès support accordé</h2>
    <p>Bonjour,</p>
    <p><strong>{$grantedBy}</strong> vous a accordé un accès temporaire à l'espace <strong>{$tenantId}</strong> sur Illizeo.</p>

    <div style="background: #f8f9fa; border-radius: 8px; padding: 16px 20px; margin: 20px 0;">
        <table style="font-size: 13px; width: 100%;">
            <tr><td style="color: #666; padding: 4px 0; width: 140px;">Email</td><td><strong>{$email}</strong></td></tr>
            <tr><td style="color: #666; padding: 4px 0;">Modules autorisés</td><td>{$modules}</td></tr>
            <tr><td style="color: #666; padding: 4px 0;">Expire le</td><td><strong>{$expiresAt}</strong></td></tr>
            <tr><td style="color: #666; padding: 4px 0;">Raison</td><td>{$reason}</td></tr>
        </table>
    </div>

    <div style="text-align: center; margin: 24px 0;">
        <a href="{$url}" style="display: inline-block; padding: 14px 32px; background: #E91E63; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600;">
            Accéder à la plateforme
        </a>
    </div>

    <p style="font-size: 12px; color: #888;">Cet accès est temporaire et sera automatiquement révoqué le {$expiresAt}. Toutes les actions sont tracées dans le journal d'audit.</p>

    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; text-align: center;">
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse
    </div>
</div>
HTML;
    }
}
