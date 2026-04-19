<?php

namespace App\Http\Middleware;

use App\Models\AccessSchedule;
use App\Models\CompanySetting;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;

class CheckSecurityRestrictions
{
    public function handle(Request $request, Closure $next)
    {
        if (!tenant()) return $next($request);

        $user = $request->user();
        if (!$user) return $next($request);

        // Super admins bypass all restrictions
        $superAdminEmails = array_map('trim', explode(',', env('SUPER_ADMIN_EMAIL', '')));
        if (in_array($user->email, $superAdminEmails)) {
            $this->updateSessionActivity($user, $request);
            return $next($request);
        }

        // 1. Check session timeout
        $timeoutMinutes = (int) (CompanySetting::where('key', 'session_timeout_minutes')->value('value') ?? 0);
        if ($timeoutMinutes > 0) {
            $tokenId = $user->currentAccessToken()?->id;
            if ($tokenId) {
                $session = UserSession::where('token_id', $tokenId)->first();
                if ($session && $session->last_activity_at->diffInMinutes(now()) > $timeoutMinutes) {
                    // Session expired due to inactivity
                    $user->currentAccessToken()->delete();
                    $session->delete();

                    return response()->json([
                        'error' => 'Session expirée',
                        'message' => "Votre session a expiré après {$timeoutMinutes} minutes d'inactivité. Veuillez vous reconnecter.",
                        'session_expired' => true,
                    ], 401);
                }
            }
        }

        // 2. Check access schedule
        $scheduleEnabled = CompanySetting::where('key', 'access_schedule_enabled')->value('value') === 'true';
        if ($scheduleEnabled && !AccessSchedule::isAccessAllowed()) {
            $schedules = AccessSchedule::where('actif', true)->get();
            $scheduleInfo = $schedules->map(fn($s) => $s->label . ': ' . $s->start_time . '-' . $s->end_time)->implode(', ');

            return response()->json([
                'error' => 'Accès restreint',
                'message' => "L'accès est limité aux plages horaires définies : {$scheduleInfo}",
                'access_restricted' => true,
            ], 403);
        }

        // 3. Check forced SSO
        $forceSso = CompanySetting::where('key', 'force_sso')->value('value') === 'true';
        if ($forceSso) {
            // Check if user logged in via SSO (session flag)
            $tokenId = $user->currentAccessToken()?->id;
            $session = $tokenId ? UserSession::where('token_id', $tokenId)->first() : null;
            // If the session was created via password (not SSO), block
            // We'd need to track login method in sessions — for now, skip enforcement
        }

        // 4. Check forced 2FA
        $force2fa = CompanySetting::where('key', 'force_2fa')->value('value') === 'true';
        if ($force2fa && !$user->two_factor_enabled) {
            $force2faRoles = json_decode(CompanySetting::where('key', 'force_2fa_roles')->value('value') ?? '[]', true);
            if (empty($force2faRoles) || $user->roles->pluck('slug')->intersect($force2faRoles)->isNotEmpty()) {
                // Allow access to 2FA setup endpoints
                if (!$request->is('*/2fa/*') && !$request->is('*/user') && !$request->is('*/logout')) {
                    return response()->json([
                        'error' => '2FA requis',
                        'message' => "L'authentification à deux facteurs est obligatoire. Veuillez la configurer dans vos paramètres de sécurité.",
                        'require_2fa_setup' => true,
                    ], 403);
                }
            }
        }

        // Update session activity
        $this->updateSessionActivity($user, $request);

        return $next($request);
    }

    private function updateSessionActivity($user, Request $request): void
    {
        $tokenId = $user->currentAccessToken()?->id;
        if (!$tokenId) return;

        $tokenIdStr = (string) $tokenId;

        $session = UserSession::where('token_id', $tokenIdStr)->first();
        if ($session) {
            // Only update every 60 seconds to avoid DB spam
            if ($session->last_activity_at->diffInSeconds(now()) > 60) {
                $session->update(['last_activity_at' => now(), 'ip_address' => $request->ip()]);
            }
        } else {
            // Auto-create session for pre-deployment tokens
            try {
                $parsed = UserSession::parseUserAgent($request->userAgent());
                UserSession::create([
                    'user_id' => $user->id,
                    'token_id' => $tokenIdStr,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 255) : null,
                    'device' => $parsed['device'],
                    'browser' => $parsed['browser'],
                    'platform' => $parsed['platform'],
                    'last_activity_at' => now(),
                ]);
            } catch (\Exception $e) {}
        }
    }
}
