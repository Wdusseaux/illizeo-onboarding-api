<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DocuSignService
{
    private string $baseUrl;
    private string $accessToken;
    private string $accountId;

    private const ENV_MAP = [
        'demo' => 'https://demo.docusign.net/restapi',
        'production_na' => 'https://na1.docusign.net/restapi',
        'production_eu' => 'https://eu.docusign.net/restapi',
    ];

    private const OAUTH_MAP = [
        'demo' => 'https://account-d.docusign.com',
        'production_na' => 'https://account.docusign.com',
        'production_eu' => 'https://account.docusign.com',
    ];

    public function __construct(string $accessToken, string $accountId, string $environment = 'demo')
    {
        $this->accessToken = $accessToken;
        $this->accountId = $accountId;
        $this->baseUrl = self::ENV_MAP[$environment] ?? self::ENV_MAP['demo'];
    }

    public static function oauthBase(string $environment = 'demo'): string
    {
        return self::OAUTH_MAP[$environment] ?? self::OAUTH_MAP['demo'];
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson();
    }

    private function accountUrl(string $path = ''): string
    {
        return "/v2.1/accounts/{$this->accountId}" . $path;
    }

    // ─── OAuth ──────────────────────────────────────────────

    /**
     * Build OAuth authorization URL
     */
    public static function buildAuthUrl(string $integrationKey, string $redirectUri, string $state, string $environment = 'demo'): string
    {
        $base = self::oauthBase($environment);
        $params = http_build_query([
            'response_type' => 'code',
            'scope' => 'signature impersonation',
            'client_id' => $integrationKey,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
        return "{$base}/oauth/auth?{$params}";
    }

    /**
     * Exchange authorization code for tokens
     */
    public static function exchangeCode(string $code, string $integrationKey, string $secretKey, string $redirectUri, string $environment = 'demo'): array
    {
        $base = self::oauthBase($environment);

        $response = Http::asForm()
            ->withBasicAuth($integrationKey, $secretKey)
            ->post("{$base}/oauth/token", [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if (!$response->successful()) {
            throw new \Exception('Token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get user info from OAuth token
     */
    public static function getUserInfo(string $accessToken, string $environment = 'demo'): array
    {
        $base = self::oauthBase($environment);

        $response = Http::withToken($accessToken)->get("{$base}/oauth/userinfo");

        if (!$response->successful()) {
            throw new \Exception('UserInfo failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Revoke a token
     */
    public static function revokeToken(string $token, string $integrationKey, string $secretKey, string $environment = 'demo'): void
    {
        $base = self::oauthBase($environment);
        Http::asForm()
            ->withBasicAuth($integrationKey, $secretKey)
            ->post("{$base}/oauth/revoke", ['token' => $token]);
    }

    // ─── Envelopes ──────────────────────────────────────────

    /**
     * Create an envelope (draft or sent)
     *
     * @param string $emailSubject
     * @param array $documents [['documentBase64' => '...', 'name' => '...', 'fileExtension' => 'pdf', 'documentId' => '1']]
     * @param array $signers [['email' => '...', 'name' => '...', 'recipientId' => '1', 'routingOrder' => '1', 'tabs' => [...]]]
     * @param string $status 'created' (draft) or 'sent'
     * @param array $options Optional: emailBlurb, etc.
     */
    public function createEnvelope(string $emailSubject, array $documents, array $signers, string $status = 'created', array $options = []): array
    {
        $payload = array_merge([
            'emailSubject' => $emailSubject,
            'status' => $status,
            'documents' => $documents,
            'recipients' => [
                'signers' => $signers,
            ],
        ], $options);

        $response = $this->client()->post($this->accountUrl('/envelopes'), $payload);

        if (!$response->successful()) {
            throw new \Exception('Create envelope failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get sender view URL (to place signature fields in DocuSign UI)
     */
    public function getSenderView(string $envelopeId, string $returnUrl): string
    {
        $response = $this->client()->post(
            $this->accountUrl("/envelopes/{$envelopeId}/views/sender"),
            ['returnUrl' => $returnUrl]
        );

        if (!$response->successful()) {
            throw new \Exception('Sender view failed: ' . $response->body());
        }

        return $response->json('url');
    }

    /**
     * Get recipient (signing) view URL
     */
    public function getRecipientView(string $envelopeId, string $email, string $name, string $recipientId, string $returnUrl): string
    {
        $response = $this->client()->post(
            $this->accountUrl("/envelopes/{$envelopeId}/views/recipient"),
            [
                'authenticationMethod' => 'none',
                'email' => $email,
                'userName' => $name,
                'recipientId' => $recipientId,
                'returnUrl' => $returnUrl,
            ]
        );

        if (!$response->successful()) {
            throw new \Exception('Recipient view failed: ' . $response->body());
        }

        return $response->json('url');
    }

    /**
     * Get envelope status & details
     */
    public function getEnvelope(string $envelopeId): array
    {
        $response = $this->client()->get($this->accountUrl("/envelopes/{$envelopeId}"));

        if (!$response->successful()) {
            throw new \Exception('Get envelope failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * List envelopes with optional filters
     */
    public function listEnvelopes(array $filters = []): array
    {
        $defaults = [
            'from_date' => now()->subDays(30)->toISOString(),
        ];

        $response = $this->client()->get(
            $this->accountUrl('/envelopes'),
            array_merge($defaults, $filters)
        );

        if (!$response->successful()) {
            throw new \Exception('List envelopes failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send a draft envelope
     */
    public function sendEnvelope(string $envelopeId): array
    {
        $response = $this->client()->put(
            $this->accountUrl("/envelopes/{$envelopeId}"),
            ['status' => 'sent']
        );

        if (!$response->successful()) {
            throw new \Exception('Send envelope failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Void an envelope
     */
    public function voidEnvelope(string $envelopeId, string $reason = 'Annulé'): array
    {
        $response = $this->client()->put(
            $this->accountUrl("/envelopes/{$envelopeId}"),
            ['status' => 'voided', 'voidedReason' => $reason]
        );

        if (!$response->successful()) {
            throw new \Exception('Void envelope failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Download signed document
     */
    public function downloadDocument(string $envelopeId, string $documentId = 'combined'): string
    {
        $response = $this->client()->get(
            $this->accountUrl("/envelopes/{$envelopeId}/documents/{$documentId}")
        );

        if (!$response->successful()) {
            throw new \Exception('Download failed: ' . $response->body());
        }

        return $response->body();
    }

    // ─── Helper: Build from Integration config ──────────────

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];

        if (empty($config['access_token'])) {
            throw new \Exception('DocuSign non connecté — access_token manquant');
        }

        return new self(
            $config['access_token'],
            $config['account_id'],
            $config['environment'] ?? 'demo'
        );
    }
}
