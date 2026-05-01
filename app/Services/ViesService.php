<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Vérifie un n° TVA intracommunautaire via VIES (commission européenne).
 * https://ec.europa.eu/taxation_customs/vies/
 *
 * Endpoint REST officiel : https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number
 *
 * Cache 24h pour éviter de re-valider à chaque rendu de page.
 */
class ViesService
{
    private const CACHE_TTL_HOURS = 24;
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /**
     * Codes pays EU + UK (UK ne fait plus partie EU mais conserve un schéma TVA).
     */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK', 'XI', // XI = Northern Ireland post-Brexit
    ];

    public static function isEuCountry(string $countryCode): bool
    {
        $code = strtoupper($countryCode);
        // Grèce utilise EL côté VIES, GR côté ISO. On accepte les deux.
        if ($code === 'GR') $code = 'EL';
        return in_array($code, self::EU_COUNTRIES);
    }

    /**
     * Vérifie un n° TVA EU. Renvoie :
     *   ['valid' => bool, 'name' => string|null, 'address' => string|null, 'cached' => bool, 'error' => string|null]
     */
    public static function validate(string $countryCode, string $vatNumber): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === 'GR') $countryCode = 'EL';

        // Nettoyage du numéro : retirer espaces, tirets, points
        $vatNumber = preg_replace('/[\s\.\-]/', '', $vatNumber);
        // Si le client a préfixé avec le code pays (FR12345...), on le retire
        if (str_starts_with(strtoupper($vatNumber), $countryCode)) {
            $vatNumber = substr($vatNumber, strlen($countryCode));
        }

        if (!self::isEuCountry($countryCode)) {
            return ['valid' => false, 'name' => null, 'address' => null, 'cached' => false, 'error' => "Pays non-EU : la vérification VIES ne s'applique qu'aux pays de l'UE et UK (XI)."];
        }

        if (empty($vatNumber)) {
            return ['valid' => false, 'name' => null, 'address' => null, 'cached' => false, 'error' => "Numéro de TVA vide."];
        }

        $cacheKey = "vies:{$countryCode}:{$vatNumber}";
        if ($cached = Cache::get($cacheKey)) {
            $cached['cached'] = true;
            return $cached;
        }

        try {
            $response = Http::timeout(15)->post(self::ENDPOINT, [
                'countryCode' => $countryCode,
                'vatNumber' => $vatNumber,
            ]);

            if (!$response->successful()) {
                return ['valid' => false, 'name' => null, 'address' => null, 'cached' => false, 'error' => "VIES indisponible (HTTP {$response->status()})"];
            }

            $data = $response->json();
            $result = [
                'valid' => (bool) ($data['valid'] ?? false),
                'name' => $data['name'] ?? null,
                'address' => $data['address'] ?? null,
                'country_code' => $data['countryCode'] ?? $countryCode,
                'vat_number' => $data['vatNumber'] ?? $vatNumber,
                'request_date' => $data['requestDate'] ?? null,
                'cached' => false,
                'error' => null,
            ];

            Cache::put($cacheKey, $result, now()->addHours(self::CACHE_TTL_HOURS));
            return $result;
        } catch (\Exception $e) {
            Log::warning("VIES check failed for {$countryCode}{$vatNumber}: " . $e->getMessage());
            return ['valid' => false, 'name' => null, 'address' => null, 'cached' => false, 'error' => "VIES indisponible : " . $e->getMessage()];
        }
    }
}
