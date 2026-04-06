<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['nom', 'sujet', 'declencheur', 'variables', 'actif', 'contenu'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'variables' => 'array',
        ];
    }
}
