<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Cooptation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Parses an uploaded CV (PDF/DOCX/image) into a structured JSON profile
 * via Claude Sonnet 4.6 with vision/document support.
 *
 * Output schema persisted into cooptations.cv_parsed_data:
 *   {
 *     current_role: string,
 *     experience_years: int,
 *     skills: string[],
 *     education: string[],
 *     languages: string[],
 *     summary: string
 *   }
 *
 * Cost: ~$0.005-0.015 per CV depending on length. Cached forever — only
 * re-parsed if the CV file changes.
 */
class CvParsingService
{
    private const MODEL = 'claude-sonnet-4-6-20250929';
    private const MAX_TOKENS = 1500;

    public function parse(Cooptation $cooptation): ?array
    {
        $apiKey = config('services.anthropic.api_key');
        if (!$apiKey) return null;
        if (!$cooptation->cv_path) return null;

        // Quota / spending cap — best-effort: skip silently if exceeded so the
        // cooptation creation flow doesn't fail just because parsing is unavailable.
        $status = \App\Services\AiUsageGuard::status('cv_parse');
        if ($status['exceeded']) {
            Log::info('CvParsingService: AI quota exceeded — skipping parse', [
                'used' => $status['used'], 'limit' => $status['effective_limit'],
            ]);
            return null;
        }

        // Read the file from disk
        try {
            if (!Storage::exists($cooptation->cv_path)) return null;
            $bytes = Storage::get($cooptation->cv_path);
            $mime = Storage::mimeType($cooptation->cv_path) ?: 'application/pdf';
        } catch (\Throwable $e) {
            Log::error('CvParsingService: file read error', ['error' => $e->getMessage(), 'path' => $cooptation->cv_path]);
            return null;
        }

        // Claude accepts PDFs as documents, images directly. Other formats (docx)
        // need conversion — fall back to filename + skip parsing.
        $isPdf = str_contains($mime, 'pdf');
        $isImage = str_starts_with($mime, 'image/');
        if (!$isPdf && !$isImage) {
            Log::info('CvParsingService: unsupported MIME, skipping', ['mime' => $mime]);
            return null;
        }

        $contentBlocks = [
            [
                'type' => $isPdf ? 'document' : 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mime,
                    'data' => base64_encode($bytes),
                ],
            ],
            [
                'type' => 'text',
                'text' => "Extrais le profil de ce CV au format JSON STRICT. Schéma exact :\n"
                    . '{"current_role": string, "experience_years": int, "skills": string[12 max], "education": string[3 max], "languages": string[5 max], "summary": string (200 char max)}'
                    . "\nRéponds UNIQUEMENT avec le JSON, rien avant ni après.",
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => self::MODEL,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => [['role' => 'user', 'content' => $contentBlocks]],
            ]);

            if (!$response->successful()) {
                Log::error('CvParsingService: Claude error', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $body = $response->json();
            $text = $body['content'][0]['text'] ?? '';
            $parsed = $this->extractJson($text);
            if (!$parsed) return null;

            $cooptation->update([
                'cv_parsed_data' => $parsed,
                'cv_parsed_at' => now(),
            ]);

            $inTok = $body['usage']['input_tokens'] ?? 0;
            $outTok = $body['usage']['output_tokens'] ?? 0;
            // Sonnet 4.6 pricing: $3/M input, $15/M output
            $cost = ($inTok * 3.0 + $outTok * 15.0) / 1_000_000;
            try {
                AiUsage::create([
                    'type' => 'cv_parse',
                    'user_id' => null,
                    'model' => self::MODEL,
                    'input_tokens' => $inTok,
                    'output_tokens' => $outTok,
                    'cost_usd' => $cost,
                    'metadata' => json_encode(['cooptation_id' => $cooptation->id]),
                ]);
            } catch (\Throwable $e) {}

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('CvParsingService exception', ['error' => $e->getMessage(), 'cooptation_id' => $cooptation->id]);
            return null;
        }
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }
}
