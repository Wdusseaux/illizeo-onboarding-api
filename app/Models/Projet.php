<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Projet extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'code',
        'statut',
        'couleur',
        'client_type',
        'client',
        'contact_prenom',
        'contact_nom',
        'societe',
        'adresse_client',
        'email_client',
        'date_debut',
        'date_fin',
        'description',
        'devise',
        'est_facturable',
        'type_budget',
        'valeur_budget',
        'prix_vente',
        'member_roles',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'est_facturable' => 'boolean',
            'valeur_budget' => 'decimal:2',
            'prix_vente' => 'decimal:2',
            'member_roles' => 'array',
        ];
    }

    // ─── Relations ───

    public function membres(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'projets_membres', 'projet_id', 'user_id')
            ->withTimestamps();
    }

    public function sousProjets(): HasMany
    {
        return $this->hasMany(SousProjet::class);
    }

    public function taches(): HasMany
    {
        return $this->hasMany(Tache::class);
    }

    public function jalons(): HasMany
    {
        return $this->hasMany(Jalon::class);
    }

    public function lignesCouts(): HasMany
    {
        return $this->hasMany(LigneCout::class);
    }

    public function tauxHoraires(): HasMany
    {
        return $this->hasMany(TauxHoraire::class);
    }
}
