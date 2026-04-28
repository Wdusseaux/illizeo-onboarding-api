<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Updates users.last_seen_at on every authenticated request, throttled to once
 * per minute per user. We use the existing column itself as the throttle (no
 * cache dependency) — works under any tenancy/cache config.
 *
 * Drives the presence indicator ("En ligne" / "Vu il y a X") shown on
 * accompagnant cards in the employee view.
 */
class TrackUserPresence
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user) {
            try {
                $last = $user->last_seen_at;
                if (!$last || now()->diffInSeconds($last, true) >= 60) {
                    $user->forceFill(['last_seen_at' => now()])->saveQuietly();
                }
            } catch (\Throwable $e) {
                // Never let presence tracking break a real API request
            }
        }
        return $next($request);
    }
}
