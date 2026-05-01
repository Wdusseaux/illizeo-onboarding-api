<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    /**
     * GET /exchange-rates  ?currencies=EUR,USD,GBP
     * Renvoie les taux CHF → devises demandées (ou toutes si non précisé).
     */
    public function index(Request $request): JsonResponse
    {
        $rates = ExchangeRateService::getRates();
        $requested = $request->query('currencies');
        if ($requested) {
            $list = array_map('strtoupper', array_map('trim', explode(',', $requested)));
            $rates = array_intersect_key($rates, array_flip($list));
        }
        return response()->json([
            'base' => 'CHF',
            'rates' => $rates,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /exchange-rates/convert?amount=29&currency=EUR
     * Renvoie le montant CHF converti en devise cible (estimation).
     */
    public function convert(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
        ]);
        $converted = ExchangeRateService::convert((float) $request->amount, $request->currency);
        if ($converted === null) {
            return response()->json(['error' => 'Devise non supportée ou taux indisponible'], 404);
        }
        return response()->json([
            'amount_chf' => (float) $request->amount,
            'currency' => strtoupper($request->currency),
            'amount_target' => $converted,
            'is_estimate' => true,
        ]);
    }
}
