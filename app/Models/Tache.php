<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tache extends Model
{
    use HasFactory;

    protected $table = 'taches';

    protected $fillable = [
        'projet_id',
        'sous_projet_id',
        'titre',
        'statut',
        'priorite',
        'lead_id',
        'due_date',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'tags' => 'array',
        ];
    }

    // ─── Relations ───

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class);
    }

    public function sousProjet(): BelongsTo
    {
        return $this->belongsTo(SousProjet::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function collaborateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'taches_membres', 'tache_id', 'user_id')
            ->withTimestamps();
    }

    public function sousTaches(): HasMany
    {
        return $this->hasMany(SousTache::class);
    }

    public function commentaires(): HasMany
    {
        return $this->hasMany(CommentaireTache::class);
    }
}
