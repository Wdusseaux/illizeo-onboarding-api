<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SuccessFactorsService
{
    private string $baseUrl;
    private array $authHeaders;

    public function __construct(string $baseUrl, string $companyId, string $username, string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        // SF Basic Auth format: username@companyId
        $this->authHeaders = [
            'Authorization' => 'Basic ' . base64_encode("{$username}@{$companyId}:{$password}"),
        ];
    }

    public static function fromOAuth(string $baseUrl, string $accessToken): self
    {
        $instance = new self($baseUrl, '', '', '');
        $instance->authHeaders = ['Authorization' => "Bearer {$accessToken}"];
        return $instance;
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders($this->authHeaders)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    }

    // ─── Connection Test ────────────────────────────────────

    /**
     * Test connection by fetching company info
     */
    public function testConnection(): array
    {
        $response = $this->client()->get('/odata/v2/User', [
            '$top' => 1,
            '$select' => 'userId,firstName,lastName,email',
            '$format' => 'json',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Connexion SuccessFactors échouée (HTTP ' . $response->status() . '): ' . $response->body());
        }

        $data = $response->json();
        $results = $data['d']['results'] ?? [];

        return [
            'connected' => true,
            'users_found' => count($results),
            'sample_user' => $results[0] ?? null,
        ];
    }

    /**
     * Get company info
     */
    public function getCompanyInfo(): array
    {
        $response = $this->client()->get('/odata/v2/FOCompany', [
            '$top' => 1,
            '$select' => 'externalCode,name,country,currency',
            '$format' => 'json',
        ]);

        if ($response->successful()) {
            $results = $response->json('d.results') ?? [];
            return $results[0] ?? [];
        }

        return [];
    }

    // ─── Users / Employees ──────────────────────────────────

    /**
     * List employees
     */
    public function listEmployees(int $top = 100, int $skip = 0, ?string $filter = null): array
    {
        $params = [
            '$top' => $top,
            '$skip' => $skip,
            '$select' => 'userId,firstName,lastName,email,department,division,location,hireDate,status',
            '$format' => 'json',
        ];

        if ($filter) {
            $params['$filter'] = $filter;
        }

        $response = $this->client()->get('/odata/v2/User', $params);

        if (!$response->successful()) {
            throw new \Exception('Erreur liste employés: ' . $response->body());
        }

        return $response->json('d.results') ?? [];
    }

    /**
     * Get single employee by userId
     */
    public function getEmployee(string $userId): array
    {
        $response = $this->client()->get("/odata/v2/User('{$userId}')", [
            '$format' => 'json',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Employé non trouvé: ' . $response->body());
        }

        return $response->json('d') ?? [];
    }

    /**
     * Get new hires (for onboarding sync)
     */
    public function getNewHires(string $fromDate): array
    {
        $filter = "hireDate ge datetime'{$fromDate}T00:00:00'";

        return $this->listEmployees(500, 0, $filter);
    }

    /**
     * Get terminated employees (for offboarding sync)
     */
    public function getTerminations(string $fromDate): array
    {
        $response = $this->client()->get('/odata/v2/EmpEmployment', [
            '$top' => 500,
            '$select' => 'personIdExternal,endDate,userId',
            '$filter' => "endDate ge datetime'{$fromDate}T00:00:00'",
            '$format' => 'json',
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('d.results') ?? [];
    }

    // ─── Job Info ───────────────────────────────────────────

    /**
     * Get employee job info (position, department, manager)
     */
    public function getJobInfo(string $userId): array
    {
        $response = $this->client()->get('/odata/v2/EmpJob', [
            '$filter' => "userId eq '{$userId}'",
            '$orderby' => 'startDate desc',
            '$top' => 1,
            '$select' => 'userId,position,department,division,location,managerId,jobTitle,startDate',
            '$format' => 'json',
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('d.results.0') ?? [];
    }

    // ─── Org Structure ──────────────────────────────────────

    /**
     * List departments
     */
    public function listDepartments(): array
    {
        $response = $this->client()->get('/odata/v2/FODepartment', [
            '$top' => 500,
            '$select' => 'externalCode,name,description',
            '$format' => 'json',
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('d.results') ?? [];
    }

    /**
     * List locations
     */
    public function listLocations(): array
    {
        $response = $this->client()->get('/odata/v2/FOLocation', [
            '$top' => 500,
            '$select' => 'externalCode,name,city,country',
            '$format' => 'json',
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('d.results') ?? [];
    }

    // ─── Helper ─────────────────────────────────────────────

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];

        if (empty($config['base_url']) || empty($config['company_id'])) {
            throw new \Exception('SuccessFactors non configuré');
        }

        if (!empty($config['access_token'])) {
            return self::fromOAuth($config['base_url'], $config['access_token']);
        }

        return new self(
            $config['base_url'],
            $config['company_id'],
            $config['username'] ?? '',
            $config['password'] ?? ''
        );
    }
}
