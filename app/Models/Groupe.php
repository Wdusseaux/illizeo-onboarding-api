<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Groupe extends Model
{
    protected $fillable = ['nom', 'description', 'couleur', 'critere_type', 'critere_valeur', 'translations'];

    protected $casts = [
        'translations' => 'array',
    ];

    public function collaborateurs(): BelongsToMany
    {
        return $this->belongsToMany(Collaborateur::class, 'collaborateur_groupe');
    }
}
