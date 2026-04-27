<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingInstance extends Model
{
    protected $fillable = [
        'recurring_meeting_id', 'collaborateur_id', 'scheduled_at', 'duree_min',
        'external_provider', 'external_event_id', 'external_join_url',
        'synced_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function recurringMeeting(): BelongsTo
    {
        return $this->belongsTo(RecurringMeeting::class);
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }
}
