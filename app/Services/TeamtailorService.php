<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Teamtailor API v1 — JSON:API format
 * Base URL: https://api.teamtailor.com/v1
 * Auth: Bearer token (API key from Settings > Integrations > API Keys)
 */
class TeamtailorService
{
    private string $baseUrl = 'https://api.teamtailor.com/v1';
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->withHeaders([
                'X-Api-Version' => '20210218',
                'Accept' => 'application/vnd.api+json',
            ]);
    }

    public function testConnection(): array
    {
        $response = $this->client()->get('/company');

        if (!$response->successful()) {
            throw new \Exception('Connexion Teamtailor échouée: ' . $response->status());
        }

        $data = $response->json('data.attributes') ?? [];
        return [
            'connected' => true,
            'company_name' => $data['name'] ?? '',
        ];
    }

    public function listCandidates(int $pageSize = 30, ?int $page = 1): array
    {
        $response = $this->client()->get('/candidates', [
            'page[size]' => $pageSize,
            'page[number]' => $page,
        ]);
        return $response->json('data') ?? [];
    }

    public function getHiredCandidates(): array
    {
        $response = $this->client()->get('/candidates', [
            'filter[status]' => 'hired',
            'page[size]' => 100,
        ]);
        return $response->json('data') ?? [];
    }

    public function listJobs(int $pageSize = 30): array
    {
        $response = $this->client()->get('/jobs', [
            'page[size]' => $pageSize,
        ]);
        return $response->json('data') ?? [];
    }

    public function listDepartments(): array
    {
        $response = $this->client()->get('/departments');
        return $response->json('data') ?? [];
    }

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        if (empty($config['api_key'])) throw new \Exception('Teamtailor non configuré');
        return new self($config['api_key']);
    }
}
