<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $pwdRules = array_merge(PasswordController::getPasswordRules(), ['confirmed']);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:users,email', new \App\Rules\NotDisposableEmail],
            'password' => $pwdRules,
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('api', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            // Log failed attempt
            try {
                $failedUser = \App\Models\User::where('email', $request->email)->first();
                \App\Models\LoginHistory::record($failedUser?->id, $request->email, false, 'password', 'wrong_password');

                // Send security notification on failed attempts
                if ($failedUser) {
                    $recentFails = \App\Models\LoginHistory::where('email', $request->email)
                        ->where('success', false)
                        ->where('created_at', '>=', now()->subMinutes(30))
                        ->count();
                    if ($recentFails >= 3) {
                        $this->sendSecurityNotification($failedUser, 'failed_attempts', $request->ip(), $recentFails);
                    }
                }
            } catch (\Exception $e) {}

            throw ValidationException::withMessages([
                'email' => ['Les identifiants sont incorrects.'],
            ]);
        }

        $user = Auth::user();

        // If 2FA is enabled, don't return a token — require 2FA verification
        if ($user->two_factor_enabled) {
            return response()->json([
                'two_factor_required' => true,
                'email' => $user->email,
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $tokenResult = $user->createToken('api', ['*'], now()->addDays(30));
        $token = $tokenResult->plainTextToken;
        $tokenId = $tokenResult->accessToken->id;

        // Create session tracking
        try {
            $parsed = \App\Models\UserSession::parseUserAgent($request->userAgent());
            \App\Models\UserSession::create([
                'user_id' => $user->id,
                'token_id' => $tokenId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 255) : null,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
                'last_activity_at' => now(),
            ]);
        } catch (\Exception $e) {}

        // Log successful login
        try {
            \App\Models\LoginHistory::record($user->id, $user->email, true, 'password');
            \App\Models\AuditLog::log('login', 'user', $user->id, $user->name, "Connexion de {$user->name} ({$user->email})");

            // Check if new IP — send notification
            $knownIps = \App\Models\LoginHistory::where('user_id', $user->id)
                ->where('success', true)
                ->where('created_at', '<', now()->subMinutes(1))
                ->pluck('ip_address')
                ->unique();
            if ($knownIps->isNotEmpty() && !$knownIps->contains($request->ip())) {
                $this->sendSecurityNotification($user, 'new_ip', $request->ip());
            }
        } catch (\Exception $e) {}

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke current token if using token auth
        if ($request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        // Also invalidate session for SPA if available
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Audit log
        try { \App\Models\AuditLog::log('logout', 'user', $request->user()->id, $request->user()->name, "Déconnexion de {$request->user()->name}"); } catch (\Exception $e) {}

        return response()->json(['message' => 'Déconnecté']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    private function userPayload(User $user): array
    {
        $user->load('collaborateur');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'collaborateur_id' => $user->collaborateur?->id,
        ];
    }

    /**
     * Send security notification email.
     */
    private function sendSecurityNotification(User $user, string $type, string $ip, int $failCount = 0): void
    {
        $notificationsEnabled = \App\Models\CompanySetting::where('key', 'security_notifications')->value('value');
        if ($notificationsEnabled === 'false') return;

        $subjects = [
            'new_ip' => 'Connexion depuis une nouvelle adresse IP',
            'failed_attempts' => "Alerte : {$failCount} tentatives de connexion échouées",
            'password_changed' => 'Votre mot de passe a été modifié',
        ];

        $messages = [
            'new_ip' => "Une connexion à votre compte Illizeo a été détectée depuis une nouvelle adresse IP : <strong>{$ip}</strong>.<br><br>Si ce n'est pas vous, changez immédiatement votre mot de passe et activez l'authentification à deux facteurs.",
            'failed_attempts' => "<strong>{$failCount} tentatives de connexion échouées</strong> ont été détectées sur votre compte dans les 30 dernières minutes depuis l'IP <strong>{$ip}</strong>.<br><br>Si ce n'est pas vous, assurez-vous que votre mot de passe est sécurisé.",
            'password_changed' => "Votre mot de passe Illizeo a été modifié depuis l'IP <strong>{$ip}</strong>.<br><br>Si ce n'est pas vous, contactez immédiatement votre administrateur.",
        ];

        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">'
            . '<div style="text-align:center;margin-bottom:30px;"><span style="font-size:24px;font-weight:700;color:#E91E63;">ILLIZEO</span></div>'
            . '<p>Bonjour ' . $user->name . ',</p>'
            . '<div style="background:#FFF3E0;border-left:4px solid #F9A825;padding:16px 20px;border-radius:4px;margin:20px 0;">'
            . ($messages[$type] ?? '')
            . '</div>'
            . '<p style="font-size:12px;color:#888;">Cet email a été envoyé automatiquement par le système de sécurité Illizeo.</p>'
            . '<div style="margin-top:30px;padding-top:16px;border-top:1px solid #eee;font-size:11px;color:#aaa;text-align:center;">Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse</div></div>';

        try {
            \Illuminate\Support\Facades\Mail::html($html, fn($m) => $m->to($user->email)->subject('Illizeo Sécurité — ' . ($subjects[$type] ?? 'Alerte')));
        } catch (\Exception $e) {
            \Log::warning("Security notification failed: {$e->getMessage()}");
        }
    }
}
