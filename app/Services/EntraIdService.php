<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class EntraIdService
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $graphUrl = 'https://graph.microsoft.com/v1.0';

    public function __construct(string $tenantId, string $clientId, string $clientSecret)
    {
        $this->tenantId = $tenantId;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    // ─── OAuth ──────────────────────────────────────────────

    /**
     * Build SSO authorization URL
     */
    public function buildAuthUrl(string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state,
        ]);

        return "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?{$params}";
    }

    /**
     * Exchange authorization code for tokens (SSO user login)
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => 'openid profile email User.Read',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Entra ID token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get user profile from access token (SSO)
     */
    public function getUserProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get("{$this->graphUrl}/me", [
            '$select' => 'id,displayName,givenName,surname,mail,userPrincipalName,jobTitle,department,officeLocation',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to get user profile: ' . $response->body());
        }

        return $response->json();
    }

    // ─── App-only (Client Credentials) ──────────────────────

    /**
     * Get app-only access token for sync operations
     */
    public function getAppToken(): string
    {
        $response = Http::asForm()->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Entra ID app token failed: ' . $response->body());
        }

        return $response->json('access_token');
    }

    // ─── Users ──────────────────────────────────────────────

    /**
     * List all users from Azure AD
     */
    public function listUsers(int $top = 100, ?string $nextLink = null): array
    {
        $token = $this->getAppToken();

        $url = $nextLink ?: "{$this->graphUrl}/users";
        $response = Http::withToken($token)->get($url, $nextLink ? [] : [
            '$top' => $top,
            '$select' => 'id,displayName,givenName,surname,mail,userPrincipalName,jobTitle,department,officeLocation,employeeHireDate,accountEnabled',
            '$filter' => "accountEnabled eq true",
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to list users: ' . $response->body());
        }

        return [
            'users' => $response->json('value') ?? [],
            'nextLink' => $response->json('@odata.nextLink'),
        ];
    }

    /**
     * Get a specific user
     */
    public function getUser(string $userId): array
    {
        $token = $this->getAppToken();

        $response = Http::withToken($token)->get("{$this->graphUrl}/users/{$userId}", [
            '$select' => 'id,displayName,givenName,surname,mail,userPrincipalName,jobTitle,department,officeLocation,employeeHireDate,accountEnabled,mobilePhone,streetAddress,city,postalCode,country',
        ]);

        if (!$response->successful()) {
            throw new \Exception('User not found: ' . $response->body());
        }

        return $response->json();
    }

    // ─── Groups ─────────────────────────────────────────────

    /**
     * List security groups
     */
    public function listGroups(int $top = 100): array
    {
        $token = $this->getAppToken();

        $response = Http::withToken($token)->get("{$this->graphUrl}/groups", [
            '$top' => $top,
            '$select' => 'id,displayName,description,securityEnabled,mailEnabled,membershipRule',
            '$filter' => "securityEnabled eq true",
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to list groups: ' . $response->body());
        }

        return $response->json('value') ?? [];
    }

    /**
     * Get members of a group
     */
    public function getGroupMembers(string $groupId): array
    {
        $token = $this->getAppToken();

        $response = Http::withToken($token)->get("{$this->graphUrl}/groups/{$groupId}/members", [
            '$select' => 'id,displayName,mail,userPrincipalName,jobTitle',
            '$top' => 500,
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('value') ?? [];
    }

    /**
     * Get groups a user belongs to
     */
    public function getUserGroups(string $userId): array
    {
        $token = $this->getAppToken();

        $response = Http::withToken($token)->get("{$this->graphUrl}/users/{$userId}/memberOf", [
            '$select' => 'id,displayName,securityEnabled',
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('value') ?? [];
    }

    // ─── Test Connection ────────────────────────────────────

    public function testConnection(): array
    {
        $token = $this->getAppToken();

        $response = Http::withToken($token)->get("{$this->graphUrl}/organization", [
            '$select' => 'id,displayName,verifiedDomains',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Connection test failed: ' . $response->body());
        }

        $org = $response->json('value.0') ?? [];
        $domains = collect($org['verifiedDomains'] ?? [])->pluck('name')->all();

        return [
            'connected' => true,
            'organization' => $org['displayName'] ?? '',
            'domains' => $domains,
        ];
    }

    // ─── Factory ────────────────────────────────────────────

    public static function fromIntegration(\App\Models\Integration $integration): self
    {
        $config = $integration->config ?? [];
        if (empty($config['tenant_id']) || empty($config['client_id']) || empty($config['client_secret'])) {
            throw new \Exception('Entra ID non configuré');
        }
        return new self($config['tenant_id'], $config['client_id'], $config['client_secret']);
    }

    public static function fromConfig(array $config): self
    {
        return new self($config['tenant_id'], $config['client_id'], $config['client_secret']);
    }
}
