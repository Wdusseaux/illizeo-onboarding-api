<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class ApiKey extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'key_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(ApiLog::class);
    }

    /**
     * Generate a new API key. Stores the hash, returns the plain key once.
     */
    public static function generateKey(string $name, ?array $scopes = null, ?string $expiresAt = null): array
    {
        $rawKey = bin2hex(random_bytes(32));
        $fullKey = 'ilz_live_sk_' . $rawKey;
        $prefix = substr($fullKey, 0, 12);

        $apiKey = static::create([
            'user_id' => auth()->id(),
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $fullKey),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        return [
            'api_key' => $apiKey,
            'plain_key' => $fullKey,
        ];
    }

    /**
     * Check if the key is currently active (not revoked and not expired).
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Scope to only active keys (not revoked, not expired).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }
}
