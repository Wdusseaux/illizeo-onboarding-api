<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionType extends Model
{
    protected $fillable = ['slug', 'label', 'icone', 'couleur_bg', 'couleur_texte'];

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }
}
