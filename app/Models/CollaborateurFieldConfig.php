<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborateurFieldConfig extends Model
{
    protected $table = 'collaborateur_field_config';
    protected $fillable = ['field_key', 'label', 'label_en', 'section', 'field_type', 'list_values', 'actif', 'obligatoire', 'ordre'];

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'obligatoire' => 'boolean', 'list_values' => 'array'];
    }
}
