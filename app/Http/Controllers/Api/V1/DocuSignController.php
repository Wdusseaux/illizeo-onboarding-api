<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\DocuSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocuSignController extends Controller
{
    private function getIntegration(?int $id = null): Integration
    {
        return $id
            ? Integration::findOrFail($id)
            : Integration::where('provider', 'docusign')->firstOrFail();
    }

    // ─── OAuth Flow ─────────────────────────────────────────

    /**
     * Step 1: Save config + redirect to DocuSign consent
     */
    public function redirect(Request $request): JsonResponse
    {
        $integration = $this->getIntegration($request->input('integration_id'));
        $config = $integration->config ?? [];

        // ISV mode: fallback to .env keys if tenant has none
        $integrationKey = $config['integration_key'] ?: config('docusign.integration_key', '');
        $environment = $config['environment'] ?? 'demo';

        if (!$integrationKey) {
            return response()->json(['error' => 'Integration Key non configurée.'], 422);
        }

        $redirectUri = config('docusign.redirect_uri', url('/api/v1/integrations/docusign/callback'));

        $state = base64_encode(json_encode([
            'tenant_id' => tenant('id'),
            'integration_id' => $integration->id,
        ]));

        $url = DocuSignService::buildAuthUrl($integrationKey, $redirectUri, $state, $environment);

        return response()->json(['redirect_url' => $url]);
    }

    /**
     * Step 2: DocuSign callback — exchange code for tokens
     */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $stateRaw = $request->query('state');
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if (!$code || !$stateRaw) {
            return redirect("{$frontendUrl}?docusign=error&reason=missing_code");
        }

        $state = json_decode(base64_decode($stateRaw), true);
        $tenantId = $state['tenant_id'] ?? null;
        $integrationId = $state['integration_id'] ?? null;

        // Initialize tenant
        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return redirect("{$frontendUrl}?docusign=error&reason=tenant_not_found");
        }
        tenancy()->initialize($tenant);

        $integration = Integration::find($integrationId);
        if (!$integration) {
            return redirect("{$frontendUrl}?docusign=error&reason=integration_not_found");
        }

        $config = $integration->config ?? [];
        $integrationKey = $config['integration_key'] ?: config('docusign.integration_key', '');
        $secretKey = $config['secret_key'] ?: config('docusign.secret_key', '');
        $environment = $config['environment'] ?? 'demo';
        $redirectUri = config('docusign.redirect_uri', url('/api/v1/integrations/docusign/callback'));

        try {
            // Exchange code for tokens
            $tokens = DocuSignService::exchangeCode($code, $integrationKey, $secretKey, $redirectUri, $environment);
            $accessToken = $tokens['access_token'];
            $refreshToken = $tokens['refresh_token'] ?? null;
            $expiresIn = $tokens['expires_in'] ?? 3600;

            // Get user info
            $userInfo = DocuSignService::getUserInfo($accessToken, $environment);
            $account = collect($userInfo['accounts'] ?? [])->first();

            // Merge with existing config (keep keys)
            $integration->update([
                'config' => array_merge($config, [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => now()->addSeconds($expiresIn)->toISOString(),
                    'user_id' => $userInfo['sub'] ?? '',
                    'user_name' => $userInfo['name'] ?? '',
                    'user_email' => $userInfo['email'] ?? '',
                    'account_id' => $account['account_id'] ?? $config['account_id'] ?? '',
                    'account_name' => $account['account_name'] ?? '',
                    'base_uri' => $account['base_uri'] ?? '',
                ]),
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return redirect("{$frontendUrl}?docusign=success");
        } catch (\Exception $e) {
            return redirect("{$frontendUrl}?docusign=error&reason=" . urlencode($e->getMessage()));
        }
    }

    /**
     * Disconnect: revoke tokens, keep keys
     */
    public function disconnect(Request $request, Integration $integration): JsonResponse
    {
        $config = $integration->config ?? [];

        if (!empty($config['access_token']) && !empty($config['integration_key'])) {
            try {
                DocuSignService::revokeToken(
                    $config['access_token'],
                    $config['integration_key'],
                    $config['secret_key'] ?? '',
                    $config['environment'] ?? 'demo'
                );
            } catch (\Exception $e) {
                // Ignore revoke errors
            }
        }

        $integration->update([
            'config' => [
                'integration_key' => $config['integration_key'] ?? '',
                'secret_key' => $config['secret_key'] ?? '',
                'account_id' => $config['account_id'] ?? '',
                'environment' => $config['environment'] ?? 'demo',
            ],
            'actif' => false,
            'connecte' => false,
            'derniere_sync' => null,
        ]);

        return response()->json(['message' => 'DocuSign déconnecté']);
    }

    // TODO: Add a webhook endpoint for DocuSign Connect events.
    // When envelope status = 'completed', fire:
    //   ContratSigned::dispatch($collaborateurId, $contratName);

    // ─── Envelope API ───────────────────────────────────────

    /**
     * Create envelope (draft) + return sender view URL
     */
    public function createEnvelope(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'email_subject' => 'required|string',
            'document_base64' => 'required|string',
            'document_name' => 'required|string',
            'signers' => 'required|array|min:1',
            'signers.*.email' => 'required|email',
            'signers.*.name' => 'required|string',
            'status' => 'nullable|in:created,sent',
        ]);

        $integration = Integration::findOrFail($request->integration_id);
        $service = DocuSignService::fromIntegration($integration);

        $documents = [[
            'documentBase64' => $request->document_base64,
            'name' => $request->document_name,
            'fileExtension' => pathinfo($request->document_name, PATHINFO_EXTENSION) ?: 'pdf',
            'documentId' => '1',
        ]];

        $signers = collect($request->signers)->map(function ($s, $i) {
            $signer = [
                'email' => $s['email'],
                'name' => $s['name'],
                'recipientId' => (string) ($i + 1),
                'routingOrder' => (string) ($s['routing_order'] ?? $i + 1),
            ];
            if (!empty($s['tabs'])) {
                $signer['tabs'] = $s['tabs'];
            }
            return $signer;
        })->all();

        $status = $request->input('status', 'created');

        $envelope = $service->createEnvelope($request->email_subject, $documents, $signers, $status);

        $result = ['envelope_id' => $envelope['envelopeId']];

        // If draft, also get sender view URL
        if ($status === 'created') {
            $returnUrl = $request->input('return_url', env('FRONTEND_URL') . '?docusign_envelope=sent');
            $senderViewUrl = $service->getSenderView($envelope['envelopeId'], $returnUrl);
            $result['sender_view_url'] = $senderViewUrl;
        }

        return response()->json($result, 201);
    }

    /**
     * Get envelope status
     */
    public function getEnvelope(Request $request, string $envelopeId): JsonResponse
    {
        $integration = $this->getIntegration($request->input('integration_id'));
        $service = DocuSignService::fromIntegration($integration);

        return response()->json($service->getEnvelope($envelopeId));
    }

    /**
     * List envelopes
     */
    public function listEnvelopes(Request $request): JsonResponse
    {
        $integration = $this->getIntegration($request->input('integration_id'));
        $service = DocuSignService::fromIntegration($integration);

        $filters = array_filter([
            'from_date' => $request->input('from_date', now()->subDays(30)->toISOString()),
            'status' => $request->input('status'),
        ]);

        return response()->json($service->listEnvelopes($filters));
    }

    /**
     * Send a draft envelope
     */
    public function sendEnvelope(Request $request, string $envelopeId): JsonResponse
    {
        $integration = $this->getIntegration($request->input('integration_id'));
        $service = DocuSignService::fromIntegration($integration);

        return response()->json($service->sendEnvelope($envelopeId));
    }

    /**
     * Get sender view for existing draft
     */
    public function senderView(Request $request, string $envelopeId): JsonResponse
    {
        $integration = $this->getIntegration($request->input('integration_id'));
        $service = DocuSignService::fromIntegration($integration);

        $returnUrl = $request->input('return_url', env('FRONTEND_URL') . '?docusign_envelope=sent');
        $url = $service->getSenderView($envelopeId, $returnUrl);

        return response()->json(['url' => $url]);
    }

    /**
     * Void an envelope
     */
    public function voidEnvelope(Request $request, string $envelopeId): JsonResponse
    {
        $integration = $this->getIntegration($request->input('integration_id'));
        $service = DocuSignService::fromIntegration($integration);

        return response()->json($service->voidEnvelope($envelopeId, $request->input('reason', 'Annulé')));
    }
}
