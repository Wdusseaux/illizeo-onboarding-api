<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'guard_name',
        'nom',
        'slug',
        'description',
        'couleur',
        'is_system',
        'is_default',
        'scope_type',
        'scope_values',
        'scope_groups',
        'exclude_self',
        'exclusion_groups',
        'members_exclusion_groups',
        'security_2fa',
        'temporary',
        'expires_at',
        'permissions',
        'ordre',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_default' => 'boolean',
            'temporary' => 'boolean',
            'actif' => 'boolean',
            'permissions' => 'array',
            'scope_values' => 'array',
            'scope_groups' => 'array',
            'exclude_self' => 'boolean',
            'exclusion_groups' => 'array',
            'members_exclusion_groups' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withPivot('assigned_by', 'assigned_at', 'expires_at')
            ->withTimestamps();
    }
}
