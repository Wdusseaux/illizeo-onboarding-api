<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupportAccess extends Model
{
    protected $fillable = [
        'email',
        'access_token',
        'granted_by',
        'allowed_modules',
        'reason',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $casts = [
        'allowed_modules' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = ['access_token'];

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * Check if this access is currently valid.
     */
    public function isActive(): bool
    {
        return !$this->revoked_at && $this->expires_at->isFuture();
    }

    /**
     * Generate a secure access token.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
