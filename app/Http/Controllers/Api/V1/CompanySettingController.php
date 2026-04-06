<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = CompanySetting::all()->pluck('value', 'key');

        return response()->json($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['settings' => 'required|array']);

        foreach ($request->settings as $key => $value) {
            CompanySetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['message' => 'Paramètres enregistrés']);
    }
}
