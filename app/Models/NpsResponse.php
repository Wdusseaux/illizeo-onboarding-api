<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NpsResponse extends Model
{
    protected $fillable = [
        'survey_id', 'collaborateur_id', 'user_id',
        'score', 'rating', 'answers', 'comment',
        'completed_at', 'token',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'score' => 'integer',
            'rating' => 'decimal:1',
            'completed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (NpsResponse $response) {
            if (empty($response->token)) {
                $response->token = Str::random(40);
            }
        });
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
