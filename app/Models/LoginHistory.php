<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    public $timestamps = false;

    protected $table = 'login_history';

    protected $fillable = [
        'user_id', 'email', 'ip_address', 'user_agent',
        'device', 'success', 'failure_reason', 'method', 'created_at',
    ];

    protected $casts = [
        'success' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log a login attempt.
     */
    public static function record(
        ?int $userId,
        string $email,
        bool $success,
        string $method = 'password',
        ?string $failureReason = null,
    ): static {
        $request = request();
        $ua = $request?->userAgent();
        $parsed = UserSession::parseUserAgent($ua);

        return static::create([
            'user_id' => $userId,
            'email' => $email,
            'ip_address' => $request?->ip(),
            'user_agent' => $ua ? substr($ua, 0, 255) : null,
            'device' => $parsed['device'],
            'success' => $success,
            'failure_reason' => $failureReason,
            'method' => $method,
            'created_at' => now(),
        ]);
    }
}
