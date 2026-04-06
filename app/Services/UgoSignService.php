<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class UgoSignService
{
    private string $baseUrl = 'https://app.ugosign.com/api';
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson();
    }

    /**
     * Test connection & get organization info
     */
    public function getOrganization(): array
    {
        $response = $this->client()->get('/v1/organization');

        if (!$response->successful()) {
            throw new \Exception('Connexion UgoSign échouée: ' . $response->status());
        }

        return $response->json('data') ?? $response->json();
    }

    /**
     * List members
     */
    public function getMembers(): array
    {
        $response = $this->client()->get('/v1/members');
        return $response->json('data') ?? [];
    }

    /**
     * Create a contact (signer)
     */
    public function createContact(array $data): array
    {
        $response = $this->client()->post('/v1/contacts', $data);

        if (!$response->successful()) {
            throw new \Exception('Erreur création contact UgoSign: ' . $response->body());
        }

        return $response->json('data');
    }

    /**
     * Create a contract (upload document)
     */
    public function createContract(string $title, string $authorId, string $filePath, array $options = []): array
    {
        $response = $this->client()
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post('/v1/contracts', array_merge([
                'title' => $title,
                'author_id' => $authorId,
            ], $options));

        if (!$response->successful()) {
            throw new \Exception('Erreur création contrat UgoSign: ' . $response->body());
        }

        return $response->json('data');
    }

    /**
     * Create an envelope (send for signature)
     *
     * @param string $contractId
     * @param string $initiatorId
     * @param array $recipients [['contact_id' => '...', 'role' => 'signer']]
     * @param string $level 'ses' | 'ses_sms' | 'aes'
     * @param string $deliveryMode 'send' | 'link' | 'live'
     * @param array $options
     */
    public function createEnvelope(
        string $contractId,
        string $initiatorId,
        array $recipients,
        string $level = 'ses',
        string $deliveryMode = 'send',
        array $options = []
    ): array {
        $response = $this->client()->post('/v1/envelopes', array_merge([
            'contract_id' => $contractId,
            'initiator_id' => $initiatorId,
            'level' => $level,
            'delivery_mode' => $deliveryMode,
            'recipients' => $recipients,
        ], $options));

        if (!$response->successful()) {
            throw new \Exception('Erreur création enveloppe UgoSign: ' . $response->body());
        }

        return $response->json('data');
    }

    /**
     * Quick envelope: create contact + contract + envelope in one call
     */
    public function quickEnvelope(array $contact, array $contract, array $envelope): array
    {
        $response = $this->client()->post('/v1/envelopes/quick', [
            'contact' => $contact,
            'contract' => $contract,
            'envelope' => $envelope,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Erreur quick envelope UgoSign: ' . $response->body());
        }

        return $response->json('data');
    }

    /**
     * Get envelope status
     */
    public function getEnvelope(string $envelopeId): array
    {
        $response = $this->client()->get("/v1/envelopes/{$envelopeId}");
        return $response->json('data') ?? [];
    }

    /**
     * List envelopes with optional status filter
     */
    public function listEnvelopes(?string $status = null): array
    {
        $query = $status ? ['status' => $status] : [];
        $response = $this->client()->get('/v1/envelopes', $query);
        return $response->json('data') ?? [];
    }

    /**
     * List contacts
     */
    public function listContacts(): array
    {
        $response = $this->client()->get('/v1/contacts');
        return $response->json('data') ?? [];
    }
}
