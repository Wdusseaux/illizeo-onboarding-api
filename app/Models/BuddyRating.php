<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyRating extends Model
{
    protected $fillable = ['user_id', 'collaborateur_id', 'target_type', 'target_user_id', 'rating', 'comment'];

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }
}
