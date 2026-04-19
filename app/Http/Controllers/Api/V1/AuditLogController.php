<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('created_at');

        // Filter by action category
        if ($request->has('category') && $request->category !== 'all') {
            $categoryMap = [
                'collabs' => ['collaborateur_created', 'collaborateur_updated', 'collaborateur_deleted'],
                'docs' => ['document_created', 'document_updated', 'document_deleted', 'signaturedocument_created', 'signaturedocument_updated', 'signaturedocument_deleted'],
                'parcours' => ['parcours_created', 'parcours_updated', 'parcours_deleted', 'action_created', 'action_updated', 'action_deleted', 'phase_created', 'phase_updated', 'phase_deleted'],
                'roles' => ['role_created', 'role_updated', 'role_deleted', 'user_created', 'user_updated', 'user_deleted'],
                'settings' => ['login', 'logout', 'password_changed', 'export_data', 'companysetting_updated', 'integration_updated'],
            ];
            $actions = $categoryMap[$request->category] ?? [];
            if (!empty($actions)) {
                $query->whereIn('action', $actions);
            }
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('entity_label', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        $limit = $request->integer('limit', 100);
        $logs = $query->limit($limit)->get();

        return response()->json($logs);
    }
}
