<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringMeeting extends Model
{
    protected $fillable = [
        'titre', 'description', 'frequence', 'jour_semaine', 'milestones',
        'heure', 'duree_min', 'lieu', 'participants_roles',
        'parcours_id', 'auto_sync_calendar', 'actif', 'translations',
    ];

    protected function casts(): array
    {
        return [
            'milestones' => 'array',
            'participants_roles' => 'array',
            'translations' => 'array',
            'auto_sync_calendar' => 'boolean',
            'actif' => 'boolean',
        ];
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(MeetingInstance::class);
    }
}
