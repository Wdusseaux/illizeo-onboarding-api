<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EmployeeController extends Controller
{
    public function markExcited(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        $cacheKey = "employee_excited:{$user->id}";
        if (Cache::has($cacheKey)) {
            $remaining = Cache::get($cacheKey);
            return response()->json([
                'ok' => false,
                'cooldown' => true,
                'message' => 'Vous avez déjà partagé votre enthousiasme aujourd\'hui',
                'until' => $remaining,
            ], 429);
        }

        $collab = Collaborateur::with(['manager:id,user_id,prenom,nom', 'hrManager:id,user_id,prenom,nom'])
            ->where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();

        $employeeName = $collab
            ? trim("{$collab->prenom} {$collab->nom}")
            : ($user->name ?: $user->email);

        $recipientUserIds = collect();
        if ($collab) {
            if ($collab->manager?->user_id) {
                $recipientUserIds->push($collab->manager->user_id);
            }
            if ($collab->hrManager?->user_id) {
                $recipientUserIds->push($collab->hrManager->user_id);
            }
        }
        $recipientUserIds = $recipientUserIds->unique()->filter()->values();

        $payload = [
            'type' => 'employee_excited',
            'title' => "✨ {$employeeName} a hâte de commencer !",
            'content' => "{$employeeName} vient de partager son enthousiasme avant son arrivée.",
            'icon' => 'party',
            'color' => '#E91E8C',
            'data' => [
                'collaborateur_id' => $collab?->id,
                'employee_name' => $employeeName,
                'date_debut' => $collab?->date_debut?->toDateString(),
            ],
        ];

        $created = 0;
        foreach ($recipientUserIds as $uid) {
            UserNotification::create(array_merge($payload, ['user_id' => $uid]));
            $created++;
        }

        Cache::put($cacheKey, now()->addDay()->toIso8601String(), now()->addDay());

        return response()->json([
            'ok' => true,
            'recipients' => $created,
            'message' => $created > 0
                ? "Votre enthousiasme a été partagé à {$created} personne" . ($created > 1 ? 's' : '') . " !"
                : 'Votre enthousiasme a été enregistré',
        ]);
    }
}
