<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingTeam extends Model
{
    protected $fillable = ['nom', 'description', 'site', 'departement', 'actif'];
    protected function casts(): array { return ['actif' => 'boolean']; }

    public function members(): HasMany { return $this->hasMany(OnboardingTeamMember::class, 'team_id'); }
}
