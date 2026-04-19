<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Contrat extends Model
{
    use Auditable;
    protected $fillable = [
        'nom', 'type', 'juridiction', 'variables',
        'derniere_maj', 'actif', 'fichier', 'fichier_path', 'translations',
    ];

    public function manager()
    {
        return null; // Contrats don't have managers, but the method is needed for merge
    }

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'derniere_maj' => 'date',
            'translations' => 'array',
        ];
    }
}
