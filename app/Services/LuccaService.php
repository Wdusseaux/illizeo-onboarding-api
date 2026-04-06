<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LuccaService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $subdomain, string $apiKey)
    {
        $this->baseUrl = "https://{$subdomain}.ilucca.net/api";
        $this->apiKey = $apiKey;
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => "lucca application={$this->apiKey}",
                'Accept' => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        $response = $this->client()->get('/v3/api/users', [
            'paging' => '0,1',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Connexion Lucca échouée (HTTP ' . $response->status() . '): ' . $response->body());
        }

        return [
            'connected' => true,
            'data' => $response->json('data.items.0') ?? null,
        ];
    }

    public function listUsers(int $limit = 100, int $offset = 0): array
    {
        $response = $this->client()->get('/v3/api/users', [
            'paging' => "{$offset},{$limit}",
        ]);

        if (!$response->successful()) {
            throw new \Exception('Erreur liste utilisateurs: ' . $response->body());
        }

        return $response->json('data.items') ?? [];
    }

    public function getUser(int $id): array
    {
        $response = $this->client()->get("/v3/api/users/{$id}");
        if (!$response->successful()) {
            throw new \Exception('Utilisateur non trouvé');
        }
        return $response->json('data') ?? [];
    }

    public function listDepartments(): array
    {
        $response = $this->client()->get('/v3/api/departments');
        if (!$response->successful()) {
            return [];
        }
        return $response->json('data.items') ?? $response->json('data') ?? [];
    }

    public function listEstablishments(): array
    {
        $response = $this->client()->get('/v3/api/establishments');
        if (!$response->successful()) {
            return [];
        }
        return $response->json('data.items') ?? $response->json('data') ?? [];
    }

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        if (empty($config['subdomain']) || empty($config['api_key'])) {
            throw new \Exception('Lucca non configuré');
        }
        return new self($config['subdomain'], $config['api_key']);
    }
}
