<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = UserNotification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit($request->input('limit', 50))
            ->get();

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, UserNotification $userNotification): JsonResponse
    {
        if ($userNotification->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $userNotification->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
