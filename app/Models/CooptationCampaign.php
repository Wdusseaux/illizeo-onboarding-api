<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CooptationCampaign extends Model
{
    protected $fillable = [
        'titre', 'description', 'departement', 'site',
        'type_contrat', 'type_recompense', 'montant_recompense',
        'description_recompense', 'mois_requis', 'statut',
        'date_limite', 'nombre_postes', 'nombre_candidatures',
        'priorite', 'share_token',
    ];

    protected function casts(): array
    {
        return [
            'montant_recompense' => 'decimal:2',
            'mois_requis' => 'integer',
            'nombre_postes' => 'integer',
            'nombre_candidatures' => 'integer',
            'date_limite' => 'date',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CooptationCampaign $campaign) {
            if (empty($campaign->share_token)) {
                $campaign->share_token = Str::random(32);
            }
        });
    }

    public function cooptations(): HasMany
    {
        return $this->hasMany(Cooptation::class, 'campaign_id');
    }
}
