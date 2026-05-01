<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborateurFieldConfig extends Model
{
    protected $table = 'collaborateur_field_config';
    protected $fillable = ['field_key', 'label', 'label_en', 'section', 'field_type', 'list_values', 'visible_roles', 'editable_roles', 'actif', 'obligatoire', 'ordre'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'obligatoire' => 'boolean',
            'list_values' => 'array',
            'visible_roles' => 'array',
            'editable_roles' => 'array',
        ];
    }

    /**
     * Tells whether a user with the given role slugs can see this field.
     * NULL or empty visible_roles array = visible to everyone (backward-compat).
     */
    public function isVisibleTo(array $userRoleSlugs): bool
    {
        if (empty($this->visible_roles)) return true;
        return (bool) array_intersect($this->visible_roles, $userRoleSlugs);
    }

    public function isEditableBy(array $userRoleSlugs): bool
    {
        if (empty($this->editable_roles)) return true;
        return (bool) array_intersect($this->editable_roles, $userRoleSlugs);
    }
}
