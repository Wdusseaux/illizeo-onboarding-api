<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class Cooptation extends Model
{
    use Auditable;

    protected function customAuditLabel(): string { return $this->candidate_name ?? "#{$this->id}"; }
    protected $fillable = [
        'referrer_name', 'referrer_email', 'referrer_user_id',
        'candidate_name', 'candidate_email', 'candidate_poste',
        'collaborateur_id', 'date_cooptation', 'date_embauche',
        'mois_requis', 'date_validation', 'statut',
        'type_recompense', 'montant_recompense', 'description_recompense',
        'recompense_versee', 'date_versement', 'notes',
        'cv_path', 'cv_original_name', 'linkedin_url', 'telephone',
        'campaign_id',
        'priority_score', 'priority_reason', 'priority_action',
        'priority_computed_at', 'priority_model_version',
        'cv_parsed_data', 'cv_parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'date_cooptation' => 'date',
            'date_embauche' => 'date',
            'date_validation' => 'date',
            'date_versement' => 'date',
            'montant_recompense' => 'decimal:2',
            'recompense_versee' => 'boolean',
            'mois_requis' => 'integer',
            'priority_score' => 'decimal:2',
            'priority_computed_at' => 'datetime',
            'cv_parsed_data' => 'array',
            'cv_parsed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::observe(\App\Observers\CooptationObserver::class);
    }

    public function referrerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CooptationCampaign::class, 'campaign_id');
    }

    public function getIsValidableAttribute(): bool
    {
        return $this->statut === 'embauche'
            && $this->date_validation
            && Carbon::today()->gte($this->date_validation);
    }

    public function getJoursRestantsAttribute(): ?int
    {
        if (!$this->date_validation) {
            return null;
        }

        $days = Carbon::today()->diffInDays($this->date_validation, false);

        return max(0, $days);
    }
}
