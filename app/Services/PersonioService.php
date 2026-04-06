<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PersonioService
{
    private string $baseUrl = 'https://api.personio.de';
    private string $accessToken;

    public function __construct(string $clientId, string $clientSecret)
    {
        $this->accessToken = $this->authenticate($clientId, $clientSecret);
    }

    public static function withToken(string $token): self
    {
        $instance = new \stdClass();
        $service = new self('', '');
        $service->accessToken = $token;
        return $service;
    }

    private function authenticate(string $clientId, string $clientSecret): string
    {
        if (!$clientId || !$clientSecret) {
            throw new \Exception('Client ID et Client Secret requis');
        }

        $response = Http::post("{$this->baseUrl}/v1/auth", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Authentification Personio échouée: ' . $response->body());
        }

        return $response->json('data.token') ?? '';
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson();
    }

    public function testConnection(): array
    {
        $response = $this->client()->get('/v1/company/employees', [
            'limit' => 1,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Connexion Personio échouée: ' . $response->body());
        }

        return [
            'connected' => true,
            'total' => $response->json('metadata.total_elements') ?? 0,
        ];
    }

    public function listEmployees(int $limit = 100, int $offset = 0): array
    {
        $response = $this->client()->get('/v1/company/employees', [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Erreur liste employés: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    public function getEmployee(int $id): array
    {
        $response = $this->client()->get("/v1/company/employees/{$id}");
        if (!$response->successful()) {
            throw new \Exception('Employé non trouvé');
        }
        return $response->json('data') ?? [];
    }

    public function listDepartments(): array
    {
        $employees = $this->listEmployees(500);
        $departments = [];
        foreach ($employees as $emp) {
            $dept = $emp['attributes']['department']['value'] ?? null;
            if ($dept && !in_array($dept, $departments)) {
                $departments[] = $dept;
            }
        }
        return $departments;
    }

    public function listAbsences(string $startDate, string $endDate): array
    {
        $response = $this->client()->get('/v1/company/time-off-types');
        return $response->successful() ? ($response->json('data') ?? []) : [];
    }

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new \Exception('Personio non configuré');
        }
        return new self($config['client_id'], $config['client_secret']);
    }
}
