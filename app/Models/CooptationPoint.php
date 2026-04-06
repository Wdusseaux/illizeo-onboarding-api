<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CooptationPoint extends Model
{
    protected $fillable = [
        'user_id', 'referrer_email', 'referrer_name',
        'cooptation_id', 'points', 'motif',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cooptation(): BelongsTo
    {
        return $this->belongsTo(Cooptation::class);
    }
}
