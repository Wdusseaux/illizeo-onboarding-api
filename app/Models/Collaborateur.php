<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collaborateur extends Model
{
    protected $fillable = [
        'user_id', 'prenom', 'nom', 'email', 'poste', 'site', 'departement',
        'date_debut', 'phase', 'progression', 'status', 'docs_valides', 'docs_total',
        'actions_completes', 'actions_total', 'initials', 'couleur', 'photo', 'parcours_id',
        // Extended fields
        'civilite', 'date_naissance', 'nationalite', 'numero_avs', 'telephone',
        'adresse', 'ville', 'code_postal', 'pays', 'iban',
        'type_contrat', 'salaire_brut', 'devise', 'taux_activite', 'periode_essai',
        'date_fin_essai', 'convention_collective', 'duree_contrat',
        'matricule', 'manager_nom', 'centre_cout', 'entite_juridique',
        'categorie_pro', 'niveau_hierarchique', 'recruteur',
        // Job Information
        'job_title', 'job_family', 'job_code', 'job_level',
        'employment_type', 'date_fin_contrat', 'motif_embauche',
        // Position Information
        'manager_id', 'hr_manager_id',
        'position_title', 'position_code', 'business_unit', 'division',
        'cost_center', 'location_code', 'dotted_line_manager',
        'work_schedule', 'fte',
        'custom_fields',
        'dossier_status', 'dossier_validated_at', 'dossier_validated_by',
        'dossier_exported_at', 'dossier_export_target',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'custom_fields' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function groupes(): BelongsToMany
    {
        return $this->belongsToMany(Groupe::class, 'collaborateur_groupe');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function assignedActions(): HasMany
    {
        return $this->hasMany(CollaborateurAction::class);
    }

    public function accompagnants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'collaborateur_accompagnants')
            ->withPivot('role', 'team_id')
            ->withTimestamps();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class, 'manager_id');
    }

    public function hrManager(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class, 'hr_manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Collaborateur::class, 'manager_id');
    }

    public function hrPopulation(): HasMany
    {
        return $this->hasMany(Collaborateur::class, 'hr_manager_id');
    }
}
