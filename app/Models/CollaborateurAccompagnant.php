<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollaborateurAccompagnant extends Model
{
    protected $fillable = ['collaborateur_id', 'user_id', 'role', 'team_id'];

    public function collaborateur(): BelongsTo { return $this->belongsTo(Collaborateur::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function team(): BelongsTo { return $this->belongsTo(OnboardingTeam::class, 'team_id'); }
}
