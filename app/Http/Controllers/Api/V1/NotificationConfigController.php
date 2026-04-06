<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(NotificationConfig::all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'canal' => 'nullable|in:email,in_app,slack',
            'actif' => 'nullable|boolean',
            'categorie' => 'nullable|string',
        ]);

        $config = NotificationConfig::create($validated);
        return response()->json($config, 201);
    }

    public function update(Request $request, NotificationConfig $notificationConfig): JsonResponse
    {
        $notificationConfig->update($request->validate([
            'nom' => 'sometimes|string|max:255',
            'canal' => 'nullable|in:email,in_app,slack',
            'actif' => 'nullable|boolean',
            'categorie' => 'nullable|string',
        ]));

        return response()->json($notificationConfig);
    }

    public function destroy(NotificationConfig $notificationConfig): JsonResponse
    {
        $notificationConfig->delete();
        return response()->json(null, 204);
    }
}
