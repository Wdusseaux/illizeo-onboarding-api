<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'nom',
        'slug',
        'description',
        'prix_eur_mensuel',
        'prix_chf_mensuel',
        'min_mensuel_eur',
        'min_mensuel_chf',
        'max_collaborateurs',
        'max_admins',
        'max_parcours',
        'max_integrations',
        'max_workflows',
        'stripe_price_id_eur',
        'stripe_price_id_chf',
        'actif',
        'populaire',
        'ordre',
    ];

    protected $casts = [
        'prix_eur_mensuel' => 'decimal:2',
        'prix_chf_mensuel' => 'decimal:2',
        'min_mensuel_eur' => 'decimal:2',
        'min_mensuel_chf' => 'decimal:2',
        'max_collaborateurs' => 'integer',
        'max_admins' => 'integer',
        'max_parcours' => 'integer',
        'max_integrations' => 'integer',
        'max_workflows' => 'integer',
        'actif' => 'boolean',
        'populaire' => 'boolean',
        'ordre' => 'integer',
    ];

    public function modules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
