<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AiUsage;
use App\Models\Subscription;

class OcrController extends Controller
{
    /**
     * Extract identity information from an uploaded ID document (passport, CNI, etc.)
     * Uses Claude Vision API to analyze the image and extract structured data.
     */
    public function extractIdentity(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        // Check AI quota — first the unified guard (covers spending cap + monthly quota),
        // then the legacy OCR-specific check below for backward-compatible messaging.
        if ($r = \App\Services\AiUsageGuard::blockIfExceeded('ocr_scan')) return $r;

        $aiQuota = $this->getAiQuota();
        if (!$aiQuota) {
            return response()->json(['error' => 'Aucun plan IA actif. Souscrivez un add-on IA pour utiliser cette fonctionnalité.', 'quota_exceeded' => true, 'no_plan' => true], 403);
        }
        $used = AiUsage::monthlyCount('ocr_scan');
        $limit = $aiQuota['ocr_limit'];
        $extraCredits = $this->getExtraCredits('ocr_scan');
        $totalAllowed = $limit + $extraCredits;
        $atWarning = $limit > 0 && $used >= (int)($limit * 0.9) && $used < $totalAllowed;
        $atLimit = $limit > 0 && $used >= $totalAllowed;

        if ($atLimit) {
            return response()->json([
                'error' => "Quota OCR atteint ({$used}/{$limit} scans + {$extraCredits} crédits supplémentaires). Achetez un pack supplémentaire ou passez au plan supérieur.",
                'quota_exceeded' => true,
                'used' => $used,
                'limit' => $limit,
                'extra_credits' => $extraCredits,
                'extra_scan_price' => $aiQuota['extra_scan_price'],
                'plan_name' => $aiQuota['plan_name'],
            ], 429);
        }

        $file = $request->file('image');
        $mimeType = $file->getMimeType();
        $base64 = base64_encode(file_get_contents($file->getRealPath()));

        // Map mime types for Claude API
        $mediaType = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            'application/pdf' => 'application/pdf',
            default => 'image/jpeg',
        };

        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) {
            return response()->json(['error' => 'Anthropic API key not configured'], 500);
        }

        try {
            // Build content based on file type
            if ($mediaType === 'application/pdf') {
                $content = [
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $this->getExtractionPrompt(),
                    ],
                ];
            } else {
                $content = [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $this->getExtractionPrompt(),
                    ],
                ];
            }

            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $aiQuota['model'] ?? config('services.anthropic.model', 'claude-opus-4-6'),
                'max_tokens' => 1024,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $content,
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Claude API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json(['error' => 'AI extraction failed', 'details' => $response->json()], 502);
            }

            $result = $response->json();
            $text = $result['content'][0]['text'] ?? '';

            // Parse JSON from Claude's response
            $extracted = $this->parseExtractedData($text);

            if (!$extracted) {
                return response()->json([
                    'error' => 'Could not parse extracted data',
                    'raw_response' => $text,
                ], 422);
            }

            // Track usage
            $inputTokens = $result['usage']['input_tokens'] ?? 0;
            $outputTokens = $result['usage']['output_tokens'] ?? 0;
            $model = $aiQuota['model'] ?? config('services.anthropic.model', 'claude-opus-4-6');
            $costUsd = $this->estimateCost($model, $inputTokens, $outputTokens);

            AiUsage::create([
                'type' => 'ocr_scan',
                'user_id' => auth()->id(),
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $costUsd,
                'metadata' => ['document_type' => $extracted['document_type'], 'confidence' => $extracted['confidence']],
            ]);

            $usedAfter = AiUsage::monthlyCount('ocr_scan');
            $warningThreshold = (int)($limit * 0.9);

            return response()->json([
                'success' => true,
                'data' => $extracted,
                'confidence' => $extracted['confidence'] ?? 'medium',
                'usage' => [
                    'used' => $usedAfter,
                    'limit' => $limit,
                    'extra_credits' => $extraCredits,
                    'percent' => $limit > 0 ? round(($usedAfter / $limit) * 100) : 0,
                ],
                'warning' => $usedAfter >= $warningThreshold ? [
                    'message' => "Vous avez utilisé {$usedAfter}/{$limit} scans OCR ce mois ({$usedAfter}%). Pensez à upgrader votre plan IA.",
                    'remaining' => max(0, $limit + $extraCredits - $usedAfter),
                    'upgrade_available' => true,
                    'extra_scan_price' => $aiQuota['extra_scan_price'],
                ] : null,
            ]);

        } catch (\Exception $e) {
            Log::error('OCR extraction error', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Extraction failed: ' . $e->getMessage()], 500);
        }
    }

    private function getExtractionPrompt(): string
    {
        return <<<'PROMPT'
You are an expert document analyst. Analyze this identity document carefully.

CRITICAL INSTRUCTIONS:
1. The document may be rotated (90°, 180°, 270°). Mentally rotate it to read it correctly.
2. If there is an MRZ (Machine Readable Zone) — the 2 lines of characters at the bottom with <<< — READ THE MRZ FIRST. It is the most reliable source for name, nationality, birth date, document number, and expiry date.
3. MRZ format for passports: Line 1 = P<COUNTRY_CODE + LAST_NAME<<FIRST_NAME<MIDDLE_NAMES. Line 2 = DOC_NUMBER + CHECK + NATIONALITY + BIRTH_DATE(YYMMDD) + CHECK + SEX + EXPIRY(YYMMDD) + CHECK + ...
4. Cross-check MRZ data with the visual fields on the document. If they conflict, prefer the MRZ.
5. Read EVERY field on the document precisely. Do not guess or invent data.

Return ONLY a valid JSON object:

{
  "document_type": "passport" | "cni" | "residence_permit" | "driver_license" | "other",
  "document_number": "string",
  "last_name": "string",
  "first_name": "string",
  "birth_date": "YYYY-MM-DD",
  "birth_place": "string",
  "nationality": "string",
  "gender": "M" | "F",
  "expiry_date": "YYYY-MM-DD",
  "issue_date": "YYYY-MM-DD",
  "issuing_authority": "string",
  "issuing_country": "string",
  "address": "string",
  "mrz_line1": "string",
  "mrz_line2": "string",
  "avs_number": "string",
  "confidence": "high" | "medium" | "low"
}

Rules:
- Dates MUST be in YYYY-MM-DD format. Convert 2-digit years: 00-30 = 2000s, 31-99 = 1900s
- Names: use original case (capitalize first letter, e.g. "Dusseaux" not "DUSSEAUX")
- Nationality: full name in French (e.g. "Française", "Suisse", "Belge")
- Return ONLY the JSON, no explanation, no markdown code blocks
PROMPT;
    }

    private function parseExtractedData(string $text): ?array
    {
        // Try to extract JSON from the response
        $text = trim($text);

        // Remove markdown code blocks if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $data = json_decode($text, true);

        if (!$data || !is_array($data)) {
            // Try to find JSON in the response
            if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $text, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        if (!$data || !is_array($data)) {
            return null;
        }

        // Normalize field names
        return [
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'birth_place' => $data['birth_place'] ?? null,
            'nationality' => $data['nationality'] ?? null,
            'gender' => $data['gender'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'issue_date' => $data['issue_date'] ?? null,
            'issuing_authority' => $data['issuing_authority'] ?? null,
            'issuing_country' => $data['issuing_country'] ?? null,
            'address' => $data['address'] ?? null,
            'avs_number' => $data['avs_number'] ?? null,
            'confidence' => $data['confidence'] ?? 'medium',
        ];
    }

    /**
     * Get current month AI usage for the tenant.
     */
    public function getUsage(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $month = (int) ($request->query('month') ?: now()->month);

        $summary = [
            'ocr_scans' => AiUsage::monthlyCount('ocr_scan', $year, $month),
            'bot_messages' => AiUsage::monthlyCount('bot_message', $year, $month),
            'contrat_generations' => AiUsage::monthlyCount('contrat_generation', $year, $month),
            'total_cost_usd' => (float) AiUsage::whereYear('created_at', $year)->whereMonth('created_at', $month)->sum('cost_usd'),
        ];

        $quota = $this->getAiQuota();

        return response()->json([
            'year' => $year,
            'month' => $month,
            'usage' => $summary,
            'quota' => $quota,
            'has_ai_plan' => $quota !== null,
        ]);
    }

    /**
     * Get AI quota info for the tenant.
     */
    public function getQuota(): JsonResponse
    {
        $quota = $this->getAiQuota();
        if (!$quota) {
            return response()->json(['has_ai_plan' => false, 'quota' => null]);
        }

        $usage = AiUsage::currentMonthSummary();

        // Usage-based billing: show consumption in CHF (cost x2)
        $usdToChf = 0.88;
        $costChf = round(($usage['total_cost_usd'] ?? 0) * $usdToChf, 4);
        $billedChf = round($costChf * 2, 4);

        return response()->json([
            'has_ai_plan' => true,
            'billing_model' => 'usage',
            'plan_name' => $quota['plan_name'],
            'usage' => $usage,
            'cost_chf' => $costChf,
            'billed_chf' => $billedChf,
            'plan_monthly_chf' => $quota['plan_monthly_chf'] ?? 0,
        ]);
    }

    /**
     * Buy extra scan credits for the current month.
     */
    public function buyExtraCredits(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:ocr_scan,bot_message,contrat_generation',
            'quantity' => 'required|integer|min:10|max:1000',
        ]);

        $aiQuota = $this->getAiQuota();
        if (!$aiQuota) {
            return response()->json(['error' => 'Aucun plan IA actif'], 403);
        }

        $type = $request->type;
        $quantity = $request->quantity;

        // Price per unit based on type
        $prices = [
            'ocr_scan' => $aiQuota['extra_scan_price'],
            'bot_message' => 0.02, // CHF per message
            'contrat_generation' => 0.15, // CHF per generation
        ];

        $unitPrice = $prices[$type] ?? 0.10;
        $totalPrice = round($quantity * $unitPrice, 2);

        // Store extra credits as a company setting
        $key = "ai_extra_{$type}_" . now()->format('Y_m');
        $current = (int) (\App\Models\CompanySetting::where('key', $key)->value('value') ?? 0);
        \App\Models\CompanySetting::updateOrCreate(
            ['key' => $key],
            ['value' => (string)($current + $quantity)]
        );

        return response()->json([
            'success' => true,
            'type' => $type,
            'added' => $quantity,
            'total_extra' => $current + $quantity,
            'unit_price_chf' => $unitPrice,
            'total_price_chf' => $totalPrice,
        ]);
    }

    /**
     * Get extra credits purchased for a given type this month.
     */
    private function getExtraCredits(string $type): int
    {
        $key = "ai_extra_{$type}_" . now()->format('Y_m');
        return (int) (\App\Models\CompanySetting::where('key', $key)->value('value') ?? 0);
    }

    /**
     * Get AI quota from the active AI subscription.
     */
    private function getAiQuota(): ?array
    {
        $tenant = tenant();
        // Check for active AI add-on subscription
        $aiSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn($q) => $q->where('addon_type', 'ai'))
            ->with('plan')
            ->first();

        if (!$aiSub) return null;

        return [
            'ocr_limit' => $aiSub->plan->ai_ocr_scans ?? 0,
            'bot_limit' => $aiSub->plan->ai_bot_messages ?? 0,
            'contrat_limit' => $aiSub->plan->ai_contrat_generations ?? 0,
            'model' => $aiSub->plan->ai_model ?? 'claude-opus-4-6',
            'extra_scan_price' => $aiSub->plan->ai_extra_scan_price_chf ?? 0.10,
            'plan_name' => $aiSub->plan->nom,
            'plan_monthly_chf' => $aiSub->plan->prix_chf_mensuel ?? 0,
        ];
    }

    /**
     * Estimate API cost based on model and tokens.
     */
    private function estimateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        // Pricing per million tokens (USD)
        $pricing = [
            'claude-opus-4-6' => ['input' => 5.0, 'output' => 25.0],
            'claude-opus-4-7' => ['input' => 5.0, 'output' => 25.0],
            'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0],
            'claude-haiku-4-5-20251001' => ['input' => 1.0, 'output' => 5.0],
        ];

        $rates = $pricing[$model] ?? $pricing['claude-opus-4-6'];
        return ($inputTokens * $rates['input'] / 1_000_000) + ($outputTokens * $rates['output'] / 1_000_000);
    }
}
