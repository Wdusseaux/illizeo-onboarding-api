<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TeamsService
{
    private ?string $webhookUrl;
    private ?string $accessToken;
    private string $graphUrl = 'https://graph.microsoft.com/v1.0';

    public function __construct(?string $webhookUrl = null, ?string $accessToken = null)
    {
        $this->webhookUrl = $webhookUrl;
        $this->accessToken = $accessToken;
    }

    // ─── Webhook Notifications ──────────────────────────────

    /**
     * Send an Adaptive Card to a Teams channel via webhook
     */
    public function sendWebhookCard(string $title, string $message, string $color = '#C2185B', array $facts = [], ?string $buttonText = null, ?string $buttonUrl = null): bool
    {
        if (!$this->webhookUrl) {
            throw new \Exception('Webhook URL non configurée');
        }

        $body = [
            [
                'type' => 'TextBlock',
                'size' => 'Medium',
                'weight' => 'Bolder',
                'text' => $title,
                'wrap' => true,
            ],
            [
                'type' => 'TextBlock',
                'text' => $message,
                'wrap' => true,
            ],
        ];

        if (!empty($facts)) {
            $body[] = [
                'type' => 'FactSet',
                'facts' => array_map(fn ($k, $v) => ['title' => $k, 'value' => (string) $v], array_keys($facts), array_values($facts)),
            ];
        }

        $actions = [];
        if ($buttonText && $buttonUrl) {
            $actions[] = [
                'type' => 'Action.OpenUrl',
                'title' => $buttonText,
                'url' => $buttonUrl,
            ];
        }

        $card = [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => $body,
                    'actions' => $actions,
                ],
            ]],
        ];

        $response = Http::post($this->webhookUrl, $card);
        return $response->successful();
    }

    /**
     * Send welcome message for new employee
     */
    public function sendWelcomeMessage(array $employee, string $parcoursName, ?string $appUrl = null): bool
    {
        $prenom = $employee['prenom'] ?? '';
        $nom = $employee['nom'] ?? '';
        $poste = $employee['poste'] ?? '';
        $site = $employee['site'] ?? '';
        $dateDebut = $employee['date_debut'] ?? '';
        $departement = $employee['departement'] ?? '';

        return $this->sendWebhookCard(
            "🎉 Bienvenue à {$prenom} {$nom} !",
            "Un(e) nouveau/nouvelle collaborateur/trice rejoint l'équipe. Souhaitons-lui la bienvenue !",
            '#4CAF50',
            [
                'Poste' => $poste,
                'Site' => $site,
                'Département' => $departement,
                'Date d\'arrivée' => $dateDebut,
                'Parcours' => $parcoursName,
            ],
            $appUrl ? 'Voir le parcours' : null,
            $appUrl
        );
    }

    /**
     * Send action overdue notification
     */
    public function sendOverdueNotification(string $collaborateur, string $action, string $delai, ?string $appUrl = null): bool
    {
        return $this->sendWebhookCard(
            "⚠️ Action en retard",
            "L'action **{$action}** pour **{$collaborateur}** est en retard (délai : {$delai}).",
            '#E53935',
            ['Collaborateur' => $collaborateur, 'Action' => $action, 'Délai' => $delai],
            $appUrl ? 'Voir dans Illizeo' : null,
            $appUrl
        );
    }

    /**
     * Send document validated notification
     */
    public function sendDocumentNotification(string $collaborateur, string $document, string $status, ?string $appUrl = null): bool
    {
        $emoji = $status === 'valide' ? '✅' : ($status === 'refuse' ? '❌' : '📄');
        $statusLabel = $status === 'valide' ? 'validé' : ($status === 'refuse' ? 'refusé' : 'soumis');

        return $this->sendWebhookCard(
            "{$emoji} Document {$statusLabel}",
            "Le document **{$document}** de **{$collaborateur}** a été {$statusLabel}.",
            $status === 'valide' ? '#4CAF50' : ($status === 'refuse' ? '#E53935' : '#1A73E8'),
            ['Collaborateur' => $collaborateur, 'Document' => $document, 'Statut' => $statusLabel],
            $appUrl ? 'Voir dans Illizeo' : null,
            $appUrl
        );
    }

    /**
     * Send parcours completed notification
     */
    public function sendParcoursCompleted(string $collaborateur, string $parcours, ?string $appUrl = null): bool
    {
        return $this->sendWebhookCard(
            "🏆 Parcours terminé !",
            "**{$collaborateur}** a complété son parcours **{$parcours}**. Félicitations !",
            '#C2185B',
            ['Collaborateur' => $collaborateur, 'Parcours' => $parcours],
            $appUrl ? 'Voir le résumé' : null,
            $appUrl
        );
    }

    // ─── Graph API — Meetings ───────────────────────────────

    private function graphClient()
    {
        if (!$this->accessToken) {
            throw new \Exception('Access token Microsoft non configuré');
        }

        return Http::baseUrl($this->graphUrl)
            ->withToken($this->accessToken)
            ->acceptJson();
    }

    /**
     * Create a Teams meeting
     */
    public function createMeeting(string $organizerEmail, string $subject, string $startDateTime, string $endDateTime, array $attendees = [], ?string $content = null): array
    {
        $attendeesList = array_map(fn ($email) => [
            'emailAddress' => ['address' => $email],
            'type' => 'required',
        ], $attendees);

        $event = [
            'subject' => $subject,
            'start' => ['dateTime' => $startDateTime, 'timeZone' => 'Europe/Paris'],
            'end' => ['dateTime' => $endDateTime, 'timeZone' => 'Europe/Paris'],
            'attendees' => $attendeesList,
            'isOnlineMeeting' => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
        ];

        if ($content) {
            $event['body'] = ['contentType' => 'HTML', 'content' => $content];
        }

        $response = $this->graphClient()->post("/users/{$organizerEmail}/events", $event);

        if (!$response->successful()) {
            throw new \Exception('Erreur création réunion Teams: ' . $response->body());
        }

        $data = $response->json();
        return [
            'id' => $data['id'],
            'subject' => $data['subject'],
            'join_url' => $data['onlineMeeting']['joinUrl'] ?? '',
            'start' => $data['start']['dateTime'] ?? '',
            'end' => $data['end']['dateTime'] ?? '',
        ];
    }

    /**
     * Get OAuth2 access token via Client Credentials (app-only)
     */
    public static function getAppToken(string $tenantId, string $clientId, string $clientSecret): string
    {
        $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Auth Microsoft échouée: ' . $response->body());
        }

        return $response->json('access_token');
    }

    /**
     * Test webhook connection
     */
    public function testWebhook(): bool
    {
        return $this->sendWebhookCard(
            '🔗 Illizeo connecté !',
            'Ce canal recevra désormais les notifications d\'onboarding.',
            '#C2185B',
            ['Statut' => 'Connecté', 'Source' => 'Illizeo Onboarding']
        );
    }

    // ─── Factory ────────────────────────────────────────────

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        return new self(
            $config['webhook_url'] ?? null,
            $config['access_token'] ?? null
        );
    }
}
