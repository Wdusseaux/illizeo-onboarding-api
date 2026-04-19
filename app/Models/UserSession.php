<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    protected $fillable = [
        'user_id', 'token_id', 'ip_address', 'user_agent',
        'device', 'browser', 'platform', 'location',
        'last_activity_at', 'expires_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Parse user agent into device/browser/platform.
     */
    public static function parseUserAgent(?string $ua): array
    {
        if (!$ua) return ['device' => 'Inconnu', 'browser' => 'Inconnu', 'platform' => 'Inconnu'];

        $browser = 'Autre';
        if (str_contains($ua, 'Chrome') && !str_contains($ua, 'Edg')) $browser = 'Chrome';
        elseif (str_contains($ua, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome')) $browser = 'Safari';
        elseif (str_contains($ua, 'Edg')) $browser = 'Edge';
        elseif (str_contains($ua, 'Opera') || str_contains($ua, 'OPR')) $browser = 'Opera';

        $platform = 'Autre';
        if (str_contains($ua, 'Windows')) $platform = 'Windows';
        elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS')) $platform = 'macOS';
        elseif (str_contains($ua, 'Linux') && !str_contains($ua, 'Android')) $platform = 'Linux';
        elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $platform = 'iOS';
        elseif (str_contains($ua, 'Android')) $platform = 'Android';

        $device = "{$browser} on {$platform}";

        return compact('device', 'browser', 'platform');
    }
}
