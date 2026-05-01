<?php

namespace App\Services;

/**
 * Calcule la TVA applicable selon le profil client (B2B/B2C, pays, n° TVA).
 *
 * Règles :
 *   - Tenant non assujetti TVA CH → 0% partout (pas de calcul)
 *   - Client suisse (toute catégorie) → TVA CH 8.1%
 *   - Client EU B2B avec n° TVA validé VIES → 0% (reverse charge, art. 196)
 *   - Client EU B2C ou EU sans n° TVA → 0% (à terme : OSS — non implémenté tant que CA EU < 10K€/an)
 *   - Client hors-EU → 0% (export de services hors champ TVA suisse)
 */
class VatCalculationService
{
    public const CH_VAT_RATE = 8.10;

    /**
     * @param int $amountHtCents       Sous-total HT en cents
     * @param string $countryCode      ISO-2 du pays du client (CH, FR, DE, ...)
     * @param string|null $customerType 'company' | 'individual' | 'freelance' (par défaut company)
     * @param string|null $vatNumber   N° TVA UE (pour reverse charge)
     * @param bool $vatNumberValidated Si true, n° TVA confirmé via VIES
     * @param bool $tenantVatRegistered Si Illizeo (le tenant qui facture) est assujetti TVA CH
     * @return array
     */
    public static function compute(
        int $amountHtCents,
        string $countryCode,
        ?string $customerType = 'company',
        ?string $vatNumber = null,
        bool $vatNumberValidated = false,
        bool $tenantVatRegistered = true,
    ): array {
        $countryCode = strtoupper($countryCode);
        $rate = 0.0;
        $treatment = 'none';
        $mention = '';

        if (!$tenantVatRegistered) {
            $treatment = 'tenant_not_vat_registered';
            $mention = 'Non assujetti à la TVA selon art. 10 al. 2 LTVA.';
        } elseif ($countryCode === 'CH' || $countryCode === 'LI') {
            // Suisse + Liechtenstein (espace TVA suisse)
            $rate = self::CH_VAT_RATE;
            $treatment = 'ch_standard';
            $mention = "TVA suisse au taux normal de " . number_format(self::CH_VAT_RATE, 2, ',', '') . "%.";
        } elseif (ViesService::isEuCountry($countryCode)) {
            if ($customerType === 'company' && $vatNumber && $vatNumberValidated) {
                $rate = 0.0;
                $treatment = 'eu_reverse_charge';
                $mention = 'TVA due par le preneur (autoliquidation, art. 196 directive 2006/112/CE). N° TVA client : ' . $vatNumber;
            } else {
                // EU B2C ou B2B sans n° TVA validé : techniquement OSS au-dessus de 10K€/an
                // Pour l'instant 0% avec mention export — à raffiner si seuil OSS atteint.
                $rate = 0.0;
                $treatment = 'eu_b2c';
                $mention = 'Vente sans TVA suisse. Le preneur peut être redevable de la TVA dans son pays.';
            }
        } else {
            // Hors-EU : export de services
            $rate = 0.0;
            $treatment = 'export';
            $mention = 'Export de services - hors champ TVA suisse.';
        }

        $vatAmountCents = (int) round(($amountHtCents * $rate) / 100);
        $totalTtcCents = $amountHtCents + $vatAmountCents;

        return [
            'rate' => $rate,
            'amount_ht_cents' => $amountHtCents,
            'vat_amount_cents' => $vatAmountCents,
            'amount_ttc_cents' => $totalTtcCents,
            'treatment' => $treatment,
            'mention' => $mention,
        ];
    }
}
