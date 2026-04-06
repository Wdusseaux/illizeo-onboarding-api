<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingTeam extends Model
{
    protected $fillable = ['nom', 'description', 'site', 'departement', 'actif', 'translations'];
    protected function casts(): array { return ['actif' => 'boolean', 'translations' => 'array']; }

    public function members(): HasMany { return $this->hasMany(OnboardingTeamMember::class, 'team_id'); }
}
