<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contrat extends Model
{
    protected $fillable = [
        'nom', 'type', 'juridiction', 'variables',
        'derniere_maj', 'actif', 'fichier', 'translations',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'derniere_maj' => 'date',
            'translations' => 'array',
        ];
    }
}
