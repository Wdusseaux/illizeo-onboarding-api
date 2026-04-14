<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyPair extends Model
{
    protected $fillable = [
        'newcomer_id', 'buddy_id', 'status', 'checklist', 'notes',
        'rating', 'feedback_comment', 'assigned_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'notes' => 'array',
            'rating' => 'decimal:1',
            'completed_at' => 'datetime',
        ];
    }

    public function newcomer(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class, 'newcomer_id');
    }

    public function buddy(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class, 'buddy_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
