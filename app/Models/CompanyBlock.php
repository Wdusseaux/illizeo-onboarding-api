<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyBlock extends Model
{
    protected $fillable = ['type', 'titre', 'contenu', 'data', 'ordre', 'actif'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'actif' => 'boolean',
        ];
    }
}
