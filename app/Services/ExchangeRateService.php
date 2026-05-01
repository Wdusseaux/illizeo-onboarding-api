<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Récupère les taux de change CHF → autres devises via exchangerate-api.com.
 *
 * - Encaissement réel : CHF uniquement (Stripe)
 * - Affichage : taux estimés en EUR/USD/GBP/etc. pour les clients étrangers
 * - Cache 24h pour éviter de spammer l'API
 */
class ExchangeRateService
{
    private const CACHE_TTL_HOURS = 24;
    private const BASE_CURRENCY = 'CHF';

    /**
     * Renvoie un map [target_currency => rate] où 1 CHF = rate target_currency.
     * Fetch automatique si cache expiré.
     *
     * @return array<string, float>
     */
    public static function getRates(): array
    {
        $expiresAt = now()->subHours(self::CACHE_TTL_HOURS);

        $rows = DB::table('exchange_rates_cache')
            ->where('base_currency', self::BASE_CURRENCY)
            ->get();

        $needsRefresh = $rows->isEmpty() || $rows->first()->fetched_at < $expiresAt->toDateTimeString();

        if ($needsRefresh) {
            self::refresh();
            $rows = DB::table('exchange_rates_cache')
                ->where('base_currency', self::BASE_CURRENCY)
                ->get();
        }

        $rates = [];
        foreach ($rows as $r) {
            $rates[$r->target_currency] = (float) $r->rate;
        }
        return $rates;
    }

    /**
     * Convertit un montant CHF vers une devise cible.
     */
    public static function convert(float $amountChf, string $targetCurrency): ?float
    {
        $target = strtoupper($targetCurrency);
        if ($target === self::BASE_CURRENCY) return $amountChf;
        $rates = self::getRates();
        return isset($rates[$target]) ? round($amountChf * $rates[$target], 2) : null;
    }

    /**
     * Va chercher les taux frais sur exchangerate-api.com et les met en cache.
     */
    public static function refresh(): bool
    {
        $apiKey = env('EXCHANGE_RATE_API_KEY');
        if (empty($apiKey)) {
            Log::warning('EXCHANGE_RATE_API_KEY non configurée');
            return false;
        }

        try {
            $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/" . self::BASE_CURRENCY;
            $response = Http::timeout(10)->get($url);

            if (!$response->successful()) {
                Log::warning('exchangerate-api a échoué', ['status' => $response->status(), 'body' => $response->body()]);
                return false;
            }

            $data = $response->json();
            if (($data['result'] ?? '') !== 'success' || !isset($data['conversion_rates'])) {
                Log::warning('exchangerate-api réponse invalide', ['data' => $data]);
                return false;
            }

            $now = now();
            // Bulk upsert : beaucoup plus rapide que 166 updateOrInsert
            $rows = [];
            foreach ($data['conversion_rates'] as $target => $rate) {
                $rows[] = [
                    'base_currency' => self::BASE_CURRENCY,
                    'target_currency' => $target,
                    'rate' => $rate,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('exchange_rates_cache')->upsert(
                $rows,
                ['base_currency', 'target_currency'],
                ['rate', 'fetched_at', 'updated_at']
            );
            return true;
        } catch (\Exception $e) {
            Log::error('Erreur refresh exchange rates : ' . $e->getMessage());
            return false;
        }
    }
}
