<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Phase extends Model
{
    protected $fillable = [
        'nom', 'delai_debut', 'delai_fin', 'couleur', 'icone',
        'actions_defaut', 'ordre', 'parcours_id', 'translations', 'active',
    ];

    protected $casts = [
        'translations' => 'array',
        'active' => 'boolean',
    ];

    public function parcours(): BelongsToMany
    {
        return $this->belongsToMany(Parcours::class, 'parcours_phase')->withPivot('ordre');
    }

    /** @deprecated Use parcours() many-to-many instead */
    public function parcoursLegacy(): BelongsTo
    {
        return $this->belongsTo(Parcours::class, 'parcours_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }
}
