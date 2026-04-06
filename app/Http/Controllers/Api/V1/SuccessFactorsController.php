<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\SuccessFactorsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuccessFactorsController extends Controller
{
    /**
     * Test connection and save config
     */
    public function connect(Request $request, Integration $integration): JsonResponse
    {
        $request->validate([
            'base_url' => 'required|url',
            'company_id' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $service = new SuccessFactorsService(
                $request->base_url,
                $request->company_id,
                $request->username,
                $request->password
            );

            $test = $service->testConnection();
            $company = $service->getCompanyInfo();

            $integration->update([
                'config' => [
                    'base_url' => $request->base_url,
                    'company_id' => $request->company_id,
                    'username' => $request->username,
                    'password' => $request->password,
                    'auth_method' => 'basic',
                    'company_name' => $company['name'] ?? $request->company_id,
                    'company_country' => $company['country'] ?? '',
                    'connected_at' => now()->toISOString(),
                ],
                'actif' => true,
                'connecte' => true,
                'derniere_sync' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'SuccessFactors connecté',
                'company' => $company,
                'test' => $test,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Disconnect
     */
    public function disconnect(Integration $integration): JsonResponse
    {
        $integration->update([
            'config' => [],
            'actif' => false,
            'connecte' => false,
            'derniere_sync' => null,
        ]);

        return response()->json(['message' => 'SuccessFactors déconnecté']);
    }

    /**
     * Sync employees from SF
     */
    public function employees(Request $request, Integration $integration): JsonResponse
    {
        $service = SuccessFactorsService::fromIntegration($integration);
        $employees = $service->listEmployees(
            $request->input('top', 100),
            $request->input('skip', 0)
        );

        return response()->json($employees);
    }

    /**
     * Get new hires for onboarding
     */
    public function newHires(Request $request, Integration $integration): JsonResponse
    {
        $service = SuccessFactorsService::fromIntegration($integration);
        $fromDate = $request->input('from_date', now()->subDays(30)->format('Y-m-d'));

        return response()->json($service->getNewHires($fromDate));
    }

    /**
     * Get org structure (departments + locations)
     */
    public function orgStructure(Integration $integration): JsonResponse
    {
        $service = SuccessFactorsService::fromIntegration($integration);

        return response()->json([
            'departments' => $service->listDepartments(),
            'locations' => $service->listLocations(),
        ]);
    }
}
