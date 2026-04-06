<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CooptationSetting extends Model
{
    protected $fillable = [
        'mois_requis_defaut', 'montant_defaut', 'type_recompense_defaut',
        'description_recompense_defaut', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'mois_requis_defaut' => 'integer',
            'montant_defaut' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }
}
