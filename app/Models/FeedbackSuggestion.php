<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackSuggestion extends Model
{
    protected $fillable = ['user_id', 'collaborateur_id', 'category', 'content', 'anonymous', 'status'];

    protected function casts(): array
    {
        return ['anonymous' => 'boolean'];
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }
}
