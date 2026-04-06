<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Badge extends Model
{
    protected $fillable = [
        'user_id', 'collaborateur_id', 'nom', 'description',
        'icon', 'color', 'earned_at', 'workflow_id',
    ];

    protected function casts(): array
    {
        return [
            'earned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
