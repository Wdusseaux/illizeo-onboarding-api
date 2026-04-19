<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Models\IpWhitelist;
use Closure;
use Illuminate\Http\Request;

class CheckIpWhitelist
{
    public function handle(Request $request, Closure $next)
    {
        // Only check if tenant is initialized
        if (!tenant()) {
            return $next($request);
        }

        // Check if IP whitelist is enabled
        $enabled = CompanySetting::where('key', 'ip_whitelist_enabled')->value('value');
        if ($enabled !== 'true' && $enabled !== '1') {
            return $next($request);
        }

        $clientIp = $request->ip();

        // Super admins (Illizeo) bypass IP whitelist
        $user = $request->user();
        if ($user) {
            $superAdminEmails = array_map('trim', explode(',', env('SUPER_ADMIN_EMAIL', '')));
            if (in_array($user->email, $superAdminEmails)) {
                return $next($request);
            }
        }

        // Support access tokens bypass IP whitelist
        if ($request->is('*/support-login')) {
            return $next($request);
        }

        // Check whitelist
        if (!IpWhitelist::isAllowed($clientIp)) {
            // Log blocked attempt
            try {
                \App\Models\AuditLog::log(
                    'ip_blocked',
                    null, null, null,
                    "Tentative d'accès bloquée depuis l'IP {$clientIp}",
                );
            } catch (\Exception $e) {}

            return response()->json([
                'error' => 'Accès refusé',
                'message' => "Votre adresse IP ({$clientIp}) n'est pas autorisée. Contactez votre administrateur.",
            ], 403);
        }

        return $next($request);
    }
}
