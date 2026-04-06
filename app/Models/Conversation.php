<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['participant_1', 'participant_2', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_1');
    }

    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_2');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function getOtherParticipant(int $userId): ?User
    {
        return $this->participant_1 === $userId ? $this->user2 : $this->user1;
    }

    public static function findOrCreateBetween(int $userId1, int $userId2): self
    {
        $min = min($userId1, $userId2);
        $max = max($userId1, $userId2);

        return self::firstOrCreate(
            ['participant_1' => $min, 'participant_2' => $max]
        );
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('participant_1', $userId)->orWhere('participant_2', $userId);
    }
}
