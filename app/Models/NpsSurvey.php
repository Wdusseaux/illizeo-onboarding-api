<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NpsSurvey extends Model
{
    protected $fillable = [
        'titre', 'description', 'type', 'parcours_id',
        'declencheur', 'date_envoi', 'questions', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'actif' => 'boolean',
            'date_envoi' => 'date',
        ];
    }

    public function responses(): HasMany
    {
        return $this->hasMany(NpsResponse::class, 'survey_id');
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }
}
