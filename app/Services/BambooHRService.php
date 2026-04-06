<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * BambooHR API v1
 * Base URL: https://api.bamboohr.com/api/gateway.php/{companyDomain}/v1
 * Auth: Basic Auth (API key as username, 'x' as password)
 */
class BambooHRService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $companyDomain, string $apiKey)
    {
        $this->baseUrl = "https://api.bamboohr.com/api/gateway.php/{$companyDomain}/v1";
        $this->apiKey = $apiKey;
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->apiKey, 'x')
            ->withHeaders(['Accept' => 'application/json']);
    }

    public function testConnection(): array
    {
        $response = $this->client()->get('/employees/directory');

        if (!$response->successful()) {
            throw new \Exception('Connexion BambooHR échouée: ' . $response->status());
        }

        $employees = $response->json('employees') ?? [];
        return [
            'connected' => true,
            'total_employees' => count($employees),
        ];
    }

    public function listEmployees(): array
    {
        $response = $this->client()->get('/employees/directory');
        return $response->json('employees') ?? [];
    }

    public function getEmployee(int $id, array $fields = ['firstName', 'lastName', 'email', 'department', 'location', 'hireDate', 'jobTitle', 'status']): array
    {
        $response = $this->client()->get("/employees/{$id}", [
            'fields' => implode(',', $fields),
        ]);
        return $response->json() ?? [];
    }

    public function getNewHires(string $since): array
    {
        $employees = $this->listEmployees();
        return array_filter($employees, fn ($e) => ($e['hireDate'] ?? '') >= $since);
    }

    public function listDepartments(): array
    {
        $employees = $this->listEmployees();
        $depts = [];
        foreach ($employees as $e) {
            $d = $e['department'] ?? null;
            if ($d && !in_array($d, $depts)) $depts[] = $d;
        }
        return $depts;
    }

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        if (empty($config['company_domain']) || empty($config['api_key'])) throw new \Exception('BambooHR non configuré');
        return new self($config['company_domain'], $config['api_key']);
    }
}
