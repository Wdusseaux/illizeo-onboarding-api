<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParcoursCategorie extends Model
{
    protected $table = 'parcours_categories';

    protected $fillable = ['slug', 'nom', 'description', 'couleur', 'icone'];

    public function parcours(): HasMany
    {
        return $this->hasMany(Parcours::class, 'categorie_id');
    }
}
