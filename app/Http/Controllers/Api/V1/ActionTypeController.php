<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionType;
use Illuminate\Http\JsonResponse;

class ActionTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ActionType::all());
    }

    public function show(ActionType $actionType): JsonResponse
    {
        return response()->json($actionType);
    }
}
