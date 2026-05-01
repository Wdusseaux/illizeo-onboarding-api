<?php

namespace App\Services;

use App\Models\CollaborateurFieldConfig;
use App\Models\User;

/**
 * Field-level role permissions for the Collaborateur model.
 *
 * The admin can mark certain fields (salaire_brut, IBAN, etc.) as visible only
 * to specific role slugs. This service centralises the filtering logic so it
 * runs the same way on read endpoints (strip hidden fields) and on write
 * endpoints (reject changes to non-editable fields).
 */
class FieldVisibilityService
{
    /**
     * Build the set of role slugs the user holds, merging custom roles and
     * legacy Spatie roles (admin, admin_rh, super_admin).
     *
     * @return array<int, string>
     */
    public static function userRoleSlugs(?User $user): array
    {
        if (!$user) return [];
        $custom = $user->customRoles()
            ->where('actif', true)
            ->pluck('slug')
            ->filter()
            ->all();
        $spatie = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->all()
            : [];
        return array_values(array_unique(array_merge($custom, $spatie)));
    }

    /**
     * Returns the list of field_key's that the user is NOT allowed to see.
     * The user can ALWAYS see their own profile in full (a user looking at
     * /me/collaborateur sees everything regardless of restrictions).
     *
     * @return array<int, string>
     */
    public static function hiddenFieldKeys(?User $user, bool $isOwnProfile = false): array
    {
        if ($isOwnProfile) return [];
        $slugs = self::userRoleSlugs($user);
        // Privileged roles bypass restrictions (legacy + custom super_admin/admin/admin_rh)
        if (array_intersect(['super_admin', 'admin', 'admin_rh'], $slugs)) return [];

        $configs = CollaborateurFieldConfig::whereNotNull('visible_roles')->get();
        $hidden = [];
        foreach ($configs as $cfg) {
            if (!$cfg->isVisibleTo($slugs)) {
                $hidden[] = $cfg->field_key;
            }
        }
        return $hidden;
    }

    /**
     * Returns the list of field_key's that the user is NOT allowed to edit.
     *
     * @return array<int, string>
     */
    public static function readOnlyFieldKeys(?User $user, bool $isOwnProfile = false): array
    {
        $slugs = self::userRoleSlugs($user);
        if (array_intersect(['super_admin', 'admin', 'admin_rh'], $slugs)) return [];

        $configs = CollaborateurFieldConfig::whereNotNull('editable_roles')->get();
        $readonly = [];
        foreach ($configs as $cfg) {
            if (!$cfg->isEditableBy($slugs)) {
                // Even on own profile, salary fields stay read-only for the collab
                $readonly[] = $cfg->field_key;
            }
        }
        return $readonly;
    }

    /**
     * Strip hidden fields from a Collaborateur array (toArray output) before
     * sending it to the user. Custom_fields entries with the same key are also
     * scrubbed.
     */
    public static function filterCollaborateurArray(array $data, ?User $user, bool $isOwnProfile = false): array
    {
        $hidden = self::hiddenFieldKeys($user, $isOwnProfile);
        if (empty($hidden)) return $data;
        foreach ($hidden as $key) {
            unset($data[$key]);
            if (isset($data['custom_fields'][$key])) {
                unset($data['custom_fields'][$key]);
            }
        }
        return $data;
    }
}
