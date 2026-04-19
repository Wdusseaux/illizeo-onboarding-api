<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class Parcours extends Model
{
    use Auditable;
    protected $table = 'parcours';

    protected $fillable = [
        'nom', 'categorie_id', 'actions_count', 'docs_count',
        'collaborateurs_actifs', 'status', 'translations',
    ];

    protected $casts = [
        'translations' => 'array',
    ];

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(ParcoursCategorie::class, 'categorie_id');
    }

    public function phases(): BelongsToMany
    {
        return $this->belongsToMany(Phase::class, 'parcours_phase')->withPivot('ordre')->orderByPivot('ordre');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }

    public function collaborateurs(): HasMany
    {
        return $this->hasMany(Collaborateur::class);
    }
}
