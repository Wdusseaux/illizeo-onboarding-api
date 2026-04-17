<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
                'model' => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
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

            return response()->json([
                'success' => true,
                'data' => $extracted,
                'confidence' => $extracted['confidence'] ?? 'medium',
            ]);

        } catch (\Exception $e) {
            Log::error('OCR extraction error', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Extraction failed: ' . $e->getMessage()], 500);
        }
    }

    private function getExtractionPrompt(): string
    {
        return <<<'PROMPT'
Analyze this identity document (passport, national ID card, residence permit, or driver's license) and extract the following information.

Return ONLY a valid JSON object with these fields (use null for fields you cannot read or that don't exist on the document):

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

Important rules:
- Dates must be in YYYY-MM-DD format
- Names should be in their original case (not all caps)
- For nationality, use the full country name in French (e.g. "Suisse", "Française", "Marocaine")
- If the document is in a non-Latin script, transliterate to Latin
- Set confidence to "high" if all key fields are clearly readable, "medium" if some are uncertain, "low" if the image is poor quality
- Return ONLY the JSON, no explanation or markdown
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
}
