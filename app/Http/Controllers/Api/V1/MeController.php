<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MeController — endpoints "Mon compte" pour l'utilisateur authentifié.
 * Sépare les actions sur soi-même des actions admin-on-user (UserManagementController).
 */
class MeController extends Controller
{
    /** GET /me/profile — retourne le profil courant. */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $prefsRaw = CompanySetting::get("user_prefs_{$user->id}");
        $prefs = $prefsRaw ? (json_decode($prefsRaw, true) ?: []) : [];
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'preferred_language' => $prefs['preferred_language'] ?? null,
            'roles' => $user->getRoleNames(),
            'created_at' => $user->created_at,
        ]);
    }

    /** PUT /me/profile — update name / email / langue préférée. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'preferred_language' => 'nullable|string|max:5',
        ]);

        if (isset($data['name'])) $user->name = $data['name'];
        if (isset($data['email'])) $user->email = $data['email'];
        $user->save();

        // preferred_language stockée dans CompanySetting (pas de colonne dédiée)
        if (array_key_exists('preferred_language', $data)) {
            $key = "user_prefs_{$user->id}";
            $current = json_decode(CompanySetting::get($key) ?: '{}', true) ?: [];
            $current['preferred_language'] = $data['preferred_language'];
            CompanySetting::updateOrCreate(['key' => $key], ['value' => json_encode($current)]);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    /** GET /me/notification-preferences — préférences perso de notification. */
    public function notificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = "user_notif_prefs_{$user->id}";
        $raw = CompanySetting::get($key);
        $prefs = $raw ? (json_decode($raw, true) ?: []) : [];
        return response()->json($prefs);
    }

    /**
     * PUT /me/notification-preferences — payload libre, structure côté front
     * (notif_id => { email: bool, sms: bool, inapp: bool }).
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = "user_notif_prefs_{$user->id}";
        $data = $request->all();
        // Sanity : doit être un objet (record)
        if (!is_array($data)) {
            return response()->json(['error' => 'Invalid payload — expected object'], 422);
        }
        CompanySetting::updateOrCreate(['key' => $key], ['value' => json_encode($data)]);
        return response()->json(['ok' => true, 'prefs' => $data]);
    }
}
