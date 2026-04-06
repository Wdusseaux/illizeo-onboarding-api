<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $fillable = [
        'provider', 'categorie', 'nom', 'config', 'actif', 'connecte', 'derniere_sync',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'actif' => 'boolean',
            'connecte' => 'boolean',
            'derniere_sync' => 'datetime',
        ];
    }

    protected $hidden = ['config']; // Don't leak secrets in API responses

    public function getConfigSafeAttribute(): array
    {
        $config = $this->config ?? [];
        $sensitiveKeys = ['client_secret', 'api_secret', 'webhook_secret', 'secret_key', 'rsa_private_key', 'access_token', 'refresh_token'];
        $masked = [];
        foreach ($config as $key => $value) {
            if (in_array($key, $sensitiveKeys)) {
                $masked[$key] = $value ? '••••••' . substr($value, -4) : null;
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }
}
