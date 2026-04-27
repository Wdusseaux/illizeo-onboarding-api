<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SlackService
{
    private ?string $webhookUrl;

    public function __construct(?string $webhookUrl = null)
    {
        $this->webhookUrl = $webhookUrl;
    }

    /**
     * Send a message to a Slack channel via Incoming Webhook
     *
     * Uses Block Kit blocks (header + section + fields + actions).
     */
    public function sendBlocks(string $title, string $message, array $facts = [], ?string $buttonText = null, ?string $buttonUrl = null, string $emoji = ':sparkles:'): bool
    {
        if (!$this->webhookUrl) {
            throw new \Exception('Webhook URL Slack non configurée');
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => "{$emoji} {$title}", 'emoji' => true],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $message],
            ],
        ];

        if (!empty($facts)) {
            $fields = [];
            foreach ($facts as $k => $v) {
                $fields[] = ['type' => 'mrkdwn', 'text' => "*{$k}*\n" . (string) $v];
            }
            $blocks[] = ['type' => 'section', 'fields' => $fields];
        }

        if ($buttonText && $buttonUrl) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => $buttonText],
                    'url' => $buttonUrl,
                    'style' => 'primary',
                ]],
            ];
        }

        $response = Http::post($this->webhookUrl, [
            'text' => $title, // fallback for clients that don't support blocks
            'blocks' => $blocks,
        ]);

        return $response->successful();
    }

    public function testWebhook(): bool
    {
        return $this->sendBlocks(
            'Illizeo connecté !',
            "Ce canal recevra désormais les notifications d'onboarding.",
            ['Statut' => 'Connecté', 'Source' => 'Illizeo Onboarding'],
            null,
            null,
            ':link:'
        );
    }

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        return new self($config['webhook_url'] ?? null);
    }
}
