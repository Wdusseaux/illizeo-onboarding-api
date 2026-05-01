<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ViesService;
use App\Services\VatCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VatController extends Controller
{
    /**
     * POST /vat/validate
     * Body: { country_code, vat_number }
     * Renvoie le résultat de la vérification VIES (avec cache 24h).
     */
    public function validateVat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => 'required|string|size:2',
            'vat_number' => 'required|string|max:32',
        ]);
        return response()->json(ViesService::validate($data['country_code'], $data['vat_number']));
    }

    /**
     * POST /vat/compute
     * Body: { amount_ht_cents, country_code, customer_type?, vat_number?, vat_validated? }
     * Calcule la TVA applicable + le total TTC selon le pays / type / n° TVA.
     */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_ht_cents' => 'required|integer|min:0',
            'country_code' => 'required|string|size:2',
            'customer_type' => 'nullable|in:company,individual,freelance',
            'vat_number' => 'nullable|string|max:32',
            'vat_validated' => 'nullable|boolean',
        ]);

        // Si le client envoie un n° TVA mais sans flag vat_validated, on valide ici via VIES
        $validated = (bool) ($data['vat_validated'] ?? false);
        if (!empty($data['vat_number']) && !$validated && ViesService::isEuCountry($data['country_code'])) {
            $check = ViesService::validate($data['country_code'], $data['vat_number']);
            $validated = (bool) ($check['valid'] ?? false);
        }

        $tenantVatRegistered = !empty(env('ILLIZEO_VAT_NUMBER')); // CHE-xxx.xxx.xxx TVA

        $result = VatCalculationService::compute(
            (int) $data['amount_ht_cents'],
            $data['country_code'],
            $data['customer_type'] ?? 'company',
            $data['vat_number'] ?? null,
            $validated,
            $tenantVatRegistered,
        );

        $result['vat_number_validated'] = $validated;
        return response()->json($result);
    }
}
