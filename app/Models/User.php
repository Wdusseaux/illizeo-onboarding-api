<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\Auditable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, Auditable;

    protected $auditExclude = ['updated_at', 'created_at', 'remember_token', 'password'];

    protected $fillable = [
        'name', 'email', 'password',
        'two_factor_secret', 'two_factor_enabled', 'two_factor_confirmed_at', 'two_factor_recovery_codes',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_recovery_codes' => 'array',
        ];
    }

    public function collaborateur(): HasOne
    {
        return $this->hasOne(Collaborateur::class);
    }

    public function customRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('assigned_by', 'assigned_at', 'expires_at')
            ->withTimestamps();
    }

    /**
     * Permission level hierarchy (higher index = more access).
     */
    private const PERMISSION_LEVELS = [
        'none'  => 0,
        'view'  => 1,
        'edit'  => 2,
        'admin' => 3,
    ];

    /**
     * Compute effective permissions by merging all active, non-expired custom roles.
     * For each module, the highest permission level wins.
     *
     * @return array<string, string>  e.g. ['parcours' => 'admin', 'collaborateurs' => 'view']
     */
    public function getEffectivePermissions(): array
    {
        $roles = $this->customRoles()
            ->where('actif', true)
            ->get();

        $effective = [];

        foreach ($roles as $role) {
            // Skip expired temporary roles (check pivot expires_at or role-level expires_at)
            $pivotExpires = $role->pivot->expires_at;
            if ($pivotExpires && now()->greaterThan($pivotExpires)) {
                continue;
            }
            if ($role->temporary && $role->expires_at && now()->greaterThan($role->expires_at)) {
                continue;
            }

            $rolePermissions = $role->permissions ?? [];

            foreach ($rolePermissions as $module => $level) {
                $currentIndex = self::PERMISSION_LEVELS[$effective[$module] ?? 'none'] ?? 0;
                $newIndex     = self::PERMISSION_LEVELS[$level] ?? 0;

                if ($newIndex > $currentIndex) {
                    $effective[$module] = $level;
                }
            }
        }

        return $effective;
    }

    /**
     * Check if the user has at least the given permission level on a module.
     * Super admins always return true.
     */
    public function hasModulePermission(string $module, string $requiredLevel = 'view'): bool
    {
        // Super admin custom role
        if ($this->customRoles()->where('slug', 'super_admin')->where('actif', true)->exists()) {
            return true;
        }

        // Backward compatibility: Spatie super_admin or admin role
        if ($this->hasRole('super_admin') || $this->hasRole('admin')) {
            return true;
        }

        $effective = $this->getEffectivePermissions();
        $userLevel = self::PERMISSION_LEVELS[$effective[$module] ?? 'none'] ?? 0;
        $required  = self::PERMISSION_LEVELS[$requiredLevel] ?? 0;

        return $userLevel >= $required;
    }
}
