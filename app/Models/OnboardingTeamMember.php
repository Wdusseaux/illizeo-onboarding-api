<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTeamMember extends Model
{
    public $timestamps = false;
    protected $fillable = ['team_id', 'user_id', 'role'];

    public function team(): BelongsTo { return $this->belongsTo(OnboardingTeam::class, 'team_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
