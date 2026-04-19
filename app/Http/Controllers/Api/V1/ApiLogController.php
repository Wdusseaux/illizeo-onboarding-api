<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiLogController extends Controller
{
    /**
     * List recent API logs with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ApiLog::with('apiKey:id,name,key_prefix')
            ->orderByDesc('created_at');

        if ($request->filled('method')) {
            $query->where('method', strtoupper($request->input('method')));
        }

        if ($request->filled('status_code')) {
            $query->where('status_code', (int) $request->input('status_code'));
        }

        if ($request->filled('api_key_id')) {
            $query->where('api_key_id', (int) $request->input('api_key_id'));
        }

        if ($request->filled('endpoint')) {
            $query->where('endpoint', 'like', '%' . $request->input('endpoint') . '%');
        }

        $limit = min((int) ($request->input('limit', 200)), 200);

        $logs = $query->limit($limit)->get();

        return response()->json($logs);
    }
}
