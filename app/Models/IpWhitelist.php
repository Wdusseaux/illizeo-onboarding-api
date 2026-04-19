<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpWhitelist extends Model
{
    protected $table = 'ip_whitelist';

    protected $fillable = ['ip_address', 'label', 'created_by'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if a given IP matches this whitelist entry (supports CIDR).
     */
    public function matches(string $ip): bool
    {
        if (str_contains($this->ip_address, '/')) {
            return self::ipInCidr($ip, $this->ip_address);
        }
        return $ip === $this->ip_address;
    }

    /**
     * Check if IP is in a CIDR range.
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int) $bits);
        return ($ip & $mask) === ($subnet & $mask);
    }

    /**
     * Check if IP is whitelisted for the current tenant.
     */
    public static function isAllowed(string $ip): bool
    {
        $entries = static::all();
        if ($entries->isEmpty()) return true; // No whitelist = allow all

        foreach ($entries as $entry) {
            if ($entry->matches($ip)) return true;
        }
        return false;
    }
}
