<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\Integration;
use App\Models\UserNotification;
use App\Services\SlackService;
use App\Services\TeamsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        $channels = $this->broadcastToChannels($collab, $employeeName);

        Cache::put($cacheKey, now()->addDay()->toIso8601String(), now()->addDay());

        $parts = [];
        if ($created > 0) $parts[] = "{$created} personne" . ($created > 1 ? 's' : '');
        foreach ($channels as $ch) $parts[] = $ch;

        return response()->json([
            'ok' => true,
            'recipients' => $created,
            'channels' => $channels,
            'message' => count($parts) > 0
                ? 'Votre enthousiasme a été partagé à ' . implode(', ', $parts) . ' !'
                : 'Votre enthousiasme a été enregistré',
        ]);
    }

    /**
     * Push a notification to connected Teams + Slack integrations.
     * Returns the list of channels that were successfully notified.
     */
    private function broadcastToChannels(?Collaborateur $collab, string $employeeName): array
    {
        $facts = [];
        if ($collab?->poste) $facts['Poste'] = $collab->poste;
        if ($collab?->departement) $facts['Département'] = $collab->departement;
        if ($collab?->date_debut) $facts['Date d\'arrivée'] = \Carbon\Carbon::parse($collab->date_debut)->format('d/m/Y');
        $title = "{$employeeName} a hâte de commencer !";
        $message = "Un(e) futur(e) collaborateur(trice) vient de partager son enthousiasme avant son arrivée. Souhaitons-lui la bienvenue dès maintenant !";

        $notified = [];

        // Teams
        try {
            $teams = Integration::where('provider', 'teams')->where('connecte', true)->first();
            if ($teams && !empty($teams->config['webhook_url'])) {
                $service = TeamsService::fromIntegration($teams);
                if ($service->sendWebhookCard("✨ {$title}", $message, '#E91E8C', $facts)) {
                    $notified[] = 'Teams';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Teams notify failed for employee_excited: ' . $e->getMessage());
        }

        // Slack
        try {
            $slack = Integration::where('provider', 'slack')->where('connecte', true)->first();
            if ($slack && !empty($slack->config['webhook_url'])) {
                $service = SlackService::fromIntegration($slack);
                if ($service->sendBlocks($title, $message, $facts, null, null, ':sparkles:')) {
                    $notified[] = 'Slack';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Slack notify failed for employee_excited: ' . $e->getMessage());
        }

        return $notified;
    }
}
