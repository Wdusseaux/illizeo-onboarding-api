<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PermissionLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    /**
     * Permission levels ordered from lowest to highest.
     */
    private const LEVELS = ['none', 'view', 'edit', 'admin'];

    /**
     * All permission modules.
     */
    private const MODULES = [
        'parcours',
        'collaborateurs',
        'documents',
        'equipements',
        'nps',
        'workflows',
        'company_page',
        'integrations',
        'settings',
        'reports',
        'cooptation',
        'contrats',
        'signatures',
        'gamification',
    ];

    /**
     * List all roles with user count.
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')
            ->orderBy('ordre')
            ->orderBy('nom')
            ->get();

        return response()->json($roles);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:roles,slug',
            'description' => 'nullable|string|max:1000',
            'couleur' => 'nullable|string|max:20',
            'is_system' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'scope_type' => 'nullable|string|in:global,site,departement,equipe',
            'scope_values' => 'nullable|array',
            'temporary' => 'nullable|boolean',
            'expires_at' => 'nullable|date',
            'permissions' => 'required|array',
            'permissions.*' => 'string|in:admin,edit,view,none',
            'ordre' => 'nullable|integer',
            'actif' => 'nullable|boolean',
        ]);

        // Auto-generate slug from nom if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['nom'], '_');
        }

        // Spatie compatibility: set name and guard_name
        $validated['name'] = $validated['slug'];
        $validated['guard_name'] = 'web';

        // If this role is marked as default, unset others
        if (!empty($validated['is_default'])) {
            Role::where('is_default', true)->update(['is_default' => false]);
        }

        $role = Role::create($validated);

        PermissionLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_created',
            'details' => ['role_id' => $role->id, 'role_nom' => $role->nom],
        ]);

        return response()->json($role->loadCount('users'), 201);
    }

    /**
     * Show a role with its users.
     */
    public function show(Role $role): JsonResponse
    {
        $role->load('users')->loadCount('users');

        return response()->json($role);
    }

    /**
     * Update a role.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'sometimes|required|string|max:255',
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('roles', 'slug')->ignore($role->id)],
            'description' => 'nullable|string|max:1000',
            'couleur' => 'nullable|string|max:20',
            'is_default' => 'nullable|boolean',
            'scope_type' => 'nullable|string|in:global,site,departement,equipe',
            'scope_values' => 'nullable|array',
            'temporary' => 'nullable|boolean',
            'expires_at' => 'nullable|date',
            'permissions' => 'sometimes|required|array',
            'permissions.*' => 'string|in:admin,edit,view,none',
            'ordre' => 'nullable|integer',
            'actif' => 'nullable|boolean',
        ]);

        // If this role is marked as default, unset others
        if (!empty($validated['is_default'])) {
            Role::where('is_default', true)->where('id', '!=', $role->id)->update(['is_default' => false]);
        }

        $role->update($validated);

        PermissionLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_updated',
            'details' => ['role_id' => $role->id, 'role_nom' => $role->nom, 'changes' => array_keys($validated)],
        ]);

        return response()->json($role->loadCount('users'));
    }

    /**
     * Delete a role (only non-system roles).
     */
    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'Impossible de supprimer un role systeme.'], 403);
        }

        PermissionLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_deleted',
            'details' => ['role_id' => $role->id, 'role_nom' => $role->nom],
        ]);

        $role->delete();

        return response()->json(['message' => 'Role supprime.']);
    }

    /**
     * Assign a user to a role.
     */
    public function assignUser(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'expires_at' => 'nullable|date',
        ]);

        // Prevent duplicate assignment
        if ($role->users()->where('user_id', $validated['user_id'])->exists()) {
            return response()->json(['message' => 'L\'utilisateur a deja ce role.'], 422);
        }

        $role->users()->attach($validated['user_id'], [
            'assigned_by' => auth()->id(),
            'assigned_at' => now(),
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        PermissionLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_assigned',
            'details' => [
                'role_id' => $role->id,
                'role_nom' => $role->nom,
                'target_user_id' => $validated['user_id'],
            ],
        ]);

        return response()->json(['message' => 'Role assigne.']);
    }

    /**
     * Remove a user from a role.
     */
    public function removeUser(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $role->users()->detach($validated['user_id']);

        PermissionLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_removed',
            'details' => [
                'role_id' => $role->id,
                'role_nom' => $role->nom,
                'target_user_id' => $validated['user_id'],
            ],
        ]);

        return response()->json(['message' => 'Role retire.']);
    }

    /**
     * Return the full permissions schema (modules + levels).
     */
    public function permissions(): JsonResponse
    {
        return response()->json([
            'modules' => self::MODULES,
            'levels' => self::LEVELS,
        ]);
    }

    /**
     * Return merged effective permissions for a given user.
     */
    public function effectivePermissions(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::with('customRoles')->findOrFail($request->user_id);

        // Start with all "none"
        $effective = array_fill_keys(self::MODULES, 'none');

        foreach ($user->customRoles as $role) {
            if (!$role->actif) {
                continue;
            }

            // Skip expired role assignments
            $pivotExpires = $role->pivot->expires_at;
            if ($pivotExpires && now()->greaterThan($pivotExpires)) {
                continue;
            }

            $rolePermissions = $role->permissions ?? [];

            foreach (self::MODULES as $module) {
                $roleLevel = $rolePermissions[$module] ?? 'none';
                $currentLevel = $effective[$module];

                // Take the highest level
                if ($this->levelIndex($roleLevel) > $this->levelIndex($currentLevel)) {
                    $effective[$module] = $roleLevel;
                }
            }
        }

        return response()->json([
            'user_id' => $user->id,
            'permissions' => $effective,
        ]);
    }

    /**
     * Return permission change logs (paginated).
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = PermissionLog::with('user')
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 25));

        return response()->json($logs);
    }

    /**
     * Duplicate a role with "(copie)" suffix.
     */
    public function duplicate(Role $role): JsonResponse
    {
        $newRole = $role->replicate(['id']);
        $newRole->nom = $role->nom . ' (copie)';
        $newRole->slug = $role->slug . '_copie_' . Str::random(4);
        $newRole->name = $newRole->slug;
        $newRole->guard_name = 'web';
        $newRole->is_system = false;
        $newRole->is_default = false;
        $newRole->save();

        PermissionLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_duplicated',
            'details' => [
                'source_role_id' => $role->id,
                'new_role_id' => $newRole->id,
                'new_role_nom' => $newRole->nom,
            ],
        ]);

        return response()->json($newRole->loadCount('users'), 201);
    }

    /**
     * Get the numeric index of a permission level.
     */
    private function levelIndex(string $level): int
    {
        $index = array_search($level, self::LEVELS, true);

        return $index !== false ? $index : 0;
    }
}
