<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Workday REST API
 * Base URL: https://{host}/ccx/api/v1/{tenant}
 * Auth: OAuth 2.0 Client Credentials or Basic Auth (ISU user)
 */
class WorkdayService
{
    private string $baseUrl;
    private string $accessToken;

    public function __construct(string $host, string $tenant, string $accessToken)
    {
        $this->baseUrl = "https://{$host}/ccx/api/v1/{$tenant}";
        $this->accessToken = $accessToken;
    }

    /**
     * Get OAuth token from Workday
     */
    public static function authenticate(string $host, string $tenant, string $clientId, string $clientSecret, string $refreshToken): string
    {
        $tokenUrl = "https://{$host}/ccx/oauth2/{$tenant}/token";

        $response = Http::asForm()->post($tokenUrl, [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Auth Workday échouée: ' . $response->body());
        }

        return $response->json('access_token');
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson();
    }

    public function testConnection(): array
    {
        $response = $this->client()->get('/workers', ['limit' => 1]);

        if (!$response->successful()) {
            throw new \Exception('Connexion Workday échouée: ' . $response->status());
        }

        return [
            'connected' => true,
            'total' => $response->json('total') ?? 0,
        ];
    }

    public function listWorkers(int $limit = 100, int $offset = 0): array
    {
        $response = $this->client()->get('/workers', [
            'limit' => $limit,
            'offset' => $offset,
        ]);
        return $response->json('data') ?? [];
    }

    public function getWorker(string $workerId): array
    {
        $response = $this->client()->get("/workers/{$workerId}");
        return $response->json() ?? [];
    }

    public function getNewHires(string $fromDate): array
    {
        $response = $this->client()->get('/workers', [
            'limit' => 500,
            'search' => json_encode(['hireDate' => ['$gte' => $fromDate]]),
        ]);
        return $response->json('data') ?? [];
    }

    public function listOrganizations(): array
    {
        $response = $this->client()->get('/organizations', ['limit' => 500]);
        return $response->json('data') ?? [];
    }

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        if (empty($config['host']) || empty($config['tenant'])) throw new \Exception('Workday non configuré');

        // Refresh token if needed
        if (!empty($config['client_id']) && !empty($config['refresh_token'])) {
            $token = self::authenticate($config['host'], $config['tenant'], $config['client_id'], $config['client_secret'] ?? '', $config['refresh_token']);
        } else {
            $token = $config['access_token'] ?? '';
        }

        if (!$token) throw new \Exception('Workday: token manquant');

        return new self($config['host'], $config['tenant'], $token);
    }
}
