<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\IpWhitelist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpWhitelistController extends Controller
{
    /**
     * Get whitelist status and entries.
     */
    public function index(Request $request): JsonResponse
    {
        $enabled = CompanySetting::where('key', 'ip_whitelist_enabled')->value('value') === 'true';
        $entries = IpWhitelist::with('createdBy:id,name')->orderByDesc('created_at')->get();

        return response()->json([
            'enabled' => $enabled,
            'entries' => $entries,
            'current_ip' => $request->ip(),
        ]);
    }

    /**
     * Toggle whitelist on/off.
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate(['enabled' => 'required|boolean']);

        $clientIp = $request->ip();
        $enabling = $request->boolean('enabled');

        // Safety: if enabling, make sure current IP is whitelisted
        if ($enabling) {
            $entries = IpWhitelist::all();
            if ($entries->isEmpty()) {
                return response()->json([
                    'error' => 'Ajoutez au moins une adresse IP avant d\'activer la whitelist.',
                ], 422);
            }

            $currentIpAllowed = false;
            foreach ($entries as $entry) {
                if ($entry->matches($clientIp)) {
                    $currentIpAllowed = true;
                    break;
                }
            }

            if (!$currentIpAllowed) {
                return response()->json([
                    'error' => "Votre IP actuelle ({$clientIp}) n'est pas dans la whitelist. Ajoutez-la d'abord pour ne pas vous bloquer.",
                ], 422);
            }
        }

        CompanySetting::updateOrCreate(
            ['key' => 'ip_whitelist_enabled'],
            ['value' => $enabling ? 'true' : 'false']
        );

        AuditLog::log(
            'ip_whitelist_toggled',
            null, null, null,
            $enabling ? 'Whitelist IP activée' : 'Whitelist IP désactivée'
        );

        return response()->json([
            'message' => $enabling ? 'Whitelist IP activée' : 'Whitelist IP désactivée',
            'enabled' => $enabling,
        ]);
    }

    /**
     * Add an IP to the whitelist.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'ip_address' => 'required|string|max:45',
            'label' => 'nullable|string|max:100',
        ]);

        $ip = trim($request->ip_address);

        // Validate IP or CIDR format
        if (str_contains($ip, '/')) {
            [$subnet, $bits] = explode('/', $ip);
            if (!filter_var($subnet, FILTER_VALIDATE_IP) || (int) $bits < 0 || (int) $bits > 32) {
                return response()->json(['error' => 'Format CIDR invalide'], 422);
            }
        } else {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return response()->json(['error' => 'Adresse IP invalide'], 422);
            }
        }

        // Check duplicate
        if (IpWhitelist::where('ip_address', $ip)->exists()) {
            return response()->json(['error' => 'Cette adresse IP est déjà dans la whitelist'], 422);
        }

        $entry = IpWhitelist::create([
            'ip_address' => $ip,
            'label' => $request->label,
            'created_by' => auth()->id(),
        ]);

        AuditLog::log('ip_whitelist_added', 'ip_whitelist', $entry->id, $ip, "IP ajoutée à la whitelist : {$ip}" . ($request->label ? " ({$request->label})" : ""));

        return response()->json(['message' => "IP {$ip} ajoutée", 'entry' => $entry->load('createdBy:id,name')], 201);
    }

    /**
     * Remove an IP from the whitelist.
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $entry = IpWhitelist::findOrFail($id);
        $ip = $entry->ip_address;

        // Safety: don't let admin remove their own IP if whitelist is active
        $enabled = CompanySetting::where('key', 'ip_whitelist_enabled')->value('value') === 'true';
        if ($enabled) {
            $clientIp = $request->ip();
            if ($entry->matches($clientIp)) {
                // Check if there's another entry that covers this IP
                $otherEntries = IpWhitelist::where('id', '!=', $id)->get();
                $stillCovered = false;
                foreach ($otherEntries as $other) {
                    if ($other->matches($clientIp)) {
                        $stillCovered = true;
                        break;
                    }
                }
                if (!$stillCovered) {
                    return response()->json([
                        'error' => "Impossible de supprimer cette IP — c'est la seule qui couvre votre IP actuelle ({$clientIp}). Vous seriez bloqué.",
                    ], 422);
                }
            }
        }

        $entry->delete();

        AuditLog::log('ip_whitelist_removed', 'ip_whitelist', $id, $ip, "IP retirée de la whitelist : {$ip}");

        return response()->json(['message' => "IP {$ip} retirée de la whitelist"]);
    }
}
