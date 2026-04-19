<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Webhook extends Model
{
    protected $fillable = [
        'user_id',
        'url',
        'events',
        'secret',
        'active',
        'last_triggered_at',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    /**
     * Dispatch an event to this webhook endpoint with HMAC-SHA256 signature.
     */
    public function dispatch(string $event, array $payload): ?WebhookLog
    {
        if (!$this->active) {
            return null;
        }

        $body = json_encode([
            'event' => $event,
            'payload' => $payload,
            'timestamp' => now()->toIso8601String(),
        ]);

        $signature = hash_hmac('sha256', $body, $this->secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $event,
                ])
                ->withBody($body, 'application/json')
                ->post($this->url);

            $this->update([
                'last_triggered_at' => now(),
                'failure_count' => $response->successful() ? 0 : $this->failure_count + 1,
            ]);

            return WebhookLog::create([
                'webhook_id' => $this->id,
                'event' => $event,
                'payload' => $payload,
                'response_status' => $response->status(),
                'response_body' => substr($response->body(), 0, 5000),
                'attempt' => 1,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            $this->increment('failure_count');
            $this->update(['last_triggered_at' => now()]);

            Log::warning("Webhook dispatch failed for webhook #{$this->id}: {$e->getMessage()}");

            return WebhookLog::create([
                'webhook_id' => $this->id,
                'event' => $event,
                'payload' => $payload,
                'response_status' => null,
                'response_body' => $e->getMessage(),
                'attempt' => 1,
                'created_at' => now(),
            ]);
        }
    }
}
