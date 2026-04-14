<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModulePermission
{
    /**
     * Permission level hierarchy (higher index = more access).
     */
    private const LEVELS = [
        'none'  => 0,
        'view'  => 1,
        'edit'  => 2,
        'admin' => 3,
    ];

    /**
     * Handle an incoming request.
     *
     * @param  string  $module         The module name (e.g. "parcours", "collaborateurs")
     * @param  string  $requiredLevel  The minimum required level (e.g. "view", "edit", "admin")
     */
    public function handle(Request $request, Closure $next, string $module, string $requiredLevel = 'view'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Super admins always pass (custom role with slug 'super_admin')
        if ($user->customRoles()->where('slug', 'super_admin')->where('actif', true)->exists()) {
            return $next($request);
        }

        // Backward compatibility: Spatie super_admin or admin role
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return $next($request);
        }

        // Load active, non-expired custom roles and compute effective permissions
        $effective = $user->getEffectivePermissions();

        $userLevel = self::LEVELS[$effective[$module] ?? 'none'] ?? 0;
        $required  = self::LEVELS[$requiredLevel] ?? 0;

        if ($userLevel >= $required) {
            return $next($request);
        }

        return response()->json([
            'message'  => 'Insufficient permissions',
            'required' => "{$module}:{$requiredLevel}",
        ], 403);
    }
}
