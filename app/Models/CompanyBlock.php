<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyBlock extends Model
{
    protected $fillable = ['type', 'titre', 'contenu', 'data', 'ordre', 'actif', 'translations'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'actif' => 'boolean',
            'translations' => 'array',
        ];
    }
}
