<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessSchedule;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\LoginHistory;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    // ─── Active Sessions ───────────────────────────────────────

    public function listSessions(): JsonResponse
    {
        $user = auth()->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        // Auto-create session for current token if it doesn't exist yet (pre-deployment sessions)
        if ($currentTokenId && !UserSession::where('token_id', (string) $currentTokenId)->exists()) {
            $request = request();
            $parsed = UserSession::parseUserAgent($request->userAgent());
            UserSession::create([
                'user_id' => $user->id,
                'token_id' => $currentTokenId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 255) : null,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
                'last_activity_at' => now(),
            ]);
        }

        $sessions = UserSession::where('user_id', $user->id)
            ->orderByDesc('last_activity_at')
            ->get();

        return response()->json([
            'sessions' => $sessions->map(fn($s) => [
                'id' => $s->id,
                'device' => $s->device,
                'browser' => $s->browser,
                'platform' => $s->platform,
                'ip_address' => $s->ip_address,
                'last_activity_at' => $s->last_activity_at,
                'is_current' => (string) $s->token_id === (string) $currentTokenId,
                'created_at' => $s->created_at,
            ]),
        ]);
    }

    public function revokeSession(int $id): JsonResponse
    {
        $session = UserSession::where('user_id', auth()->id())->findOrFail($id);

        // Delete the associated token
        if ($session->token_id) {
            \Laravel\Sanctum\PersonalAccessToken::where('id', $session->token_id)->delete();
        }

        $session->delete();

        AuditLog::log('session_revoked', 'user_session', $id, null, "Session révoquée (IP: {$session->ip_address}, {$session->device})");

        return response()->json(['message' => 'Session révoquée']);
    }

    public function revokeAllOtherSessions(): JsonResponse
    {
        $currentTokenId = auth()->user()->currentAccessToken()?->id;

        $sessions = UserSession::where('user_id', auth()->id())
            ->where('token_id', '!=', $currentTokenId)
            ->get();

        foreach ($sessions as $session) {
            if ($session->token_id) {
                \Laravel\Sanctum\PersonalAccessToken::where('id', $session->token_id)->delete();
            }
            $session->delete();
        }

        AuditLog::log('all_sessions_revoked', null, null, null, "Toutes les autres sessions révoquées ({$sessions->count()})");

        return response()->json(['message' => "{$sessions->count()} session(s) révoquée(s)"]);
    }

    // ─── Login History ─────────────────────────────────────────

    public function loginHistory(): JsonResponse
    {
        $history = LoginHistory::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($history);
    }

    public function allLoginHistory(): JsonResponse
    {
        $history = LoginHistory::orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json($history);
    }

    // ─── Security Settings ─────────────────────────────────────

    public function getSecuritySettings(): JsonResponse
    {
        $settings = CompanySetting::whereIn('key', [
            'force_sso', 'force_2fa', 'force_2fa_roles',
            'session_timeout_minutes', 'security_notifications',
            'ip_whitelist_enabled', 'access_schedule_enabled',
        ])->pluck('value', 'key');

        $schedules = AccessSchedule::orderBy('id')->get();

        return response()->json([
            'force_sso' => ($settings['force_sso'] ?? 'false') === 'true',
            'force_2fa' => ($settings['force_2fa'] ?? 'false') === 'true',
            'force_2fa_roles' => json_decode($settings['force_2fa_roles'] ?? '[]', true),
            'session_timeout_minutes' => (int) ($settings['session_timeout_minutes'] ?? 0), // 0 = no timeout
            'security_notifications' => ($settings['security_notifications'] ?? 'true') === 'true',
            'ip_whitelist_enabled' => ($settings['ip_whitelist_enabled'] ?? 'false') === 'true',
            'access_schedule_enabled' => ($settings['access_schedule_enabled'] ?? 'false') === 'true',
            'access_schedules' => $schedules,
        ]);
    }

    public function updateSecuritySettings(Request $request): JsonResponse
    {
        $allowed = [
            'force_sso', 'force_2fa', 'force_2fa_roles',
            'session_timeout_minutes', 'security_notifications',
            'access_schedule_enabled',
        ];

        foreach ($allowed as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                if (is_array($value)) $value = json_encode($value);
                if (is_bool($value)) $value = $value ? 'true' : 'false';
                CompanySetting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
            }
        }

        AuditLog::log('security_settings_updated', null, null, null, 'Paramètres de sécurité mis à jour');

        return response()->json(['message' => 'Paramètres de sécurité mis à jour']);
    }

    // ─── Access Schedules ──────────────────────────────────────

    public function storeSchedule(Request $request): JsonResponse
    {
        $request->validate([
            'label' => 'nullable|string|max:100',
            'days' => 'required|array',
            'days.*' => 'integer|between:1,7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'timezone' => 'nullable|string|max:50',
        ]);

        $schedule = AccessSchedule::create([
            'label' => $request->label,
            'days' => $request->days,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'timezone' => $request->timezone ?? 'Europe/Zurich',
            'actif' => true,
        ]);

        AuditLog::log('access_schedule_created', 'access_schedule', $schedule->id, $request->label, "Plage horaire créée : {$request->start_time}-{$request->end_time}");

        return response()->json($schedule, 201);
    }

    public function deleteSchedule(int $id): JsonResponse
    {
        $schedule = AccessSchedule::findOrFail($id);
        $schedule->delete();

        AuditLog::log('access_schedule_deleted', 'access_schedule', $id, null, 'Plage horaire supprimée');

        return response()->json(['message' => 'Plage horaire supprimée']);
    }
}
