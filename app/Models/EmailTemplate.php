<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class EmailTemplate extends Model
{
    use Auditable;
    protected $fillable = ['nom', 'sujet', 'declencheur', 'variables', 'actif', 'contenu', 'translations'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'variables' => 'array',
            'translations' => 'array',
        ];
    }
}
