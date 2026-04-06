<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollaborateurAction extends Model
{
    protected $fillable = ['collaborateur_id', 'action_id', 'status', 'started_at', 'completed_at', 'note', 'response_data'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'response_data' => 'array',
        ];
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }
}
