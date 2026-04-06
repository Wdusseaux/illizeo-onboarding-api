<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\BadgeTemplate;

class BadgeAutoAwardService
{
    /**
     * Check and award badges for a given user based on active auto-award criteria.
     */
    public static function checkAndAward(int $userId, string $triggerCritere, ?int $collaborateurId = null): void
    {
        $templates = BadgeTemplate::where('actif', true)
            ->where('critere', $triggerCritere)
            ->get();

        foreach ($templates as $template) {
            // Don't award the same badge twice to the same user
            $alreadyEarned = Badge::where('user_id', $userId)
                ->where('nom', $template->nom)
                ->exists();

            if ($alreadyEarned) {
                continue;
            }

            $badge = Badge::create([
                'user_id' => $userId,
                'collaborateur_id' => $collaborateurId,
                'nom' => $template->nom,
                'description' => "Attribution automatique : {$template->description}",
                'icon' => $template->icon,
                'color' => $template->color,
                'earned_at' => now(),
            ]);

            NotificationService::send(
                $userId,
                'badge_earned',
                'Nouveau badge !',
                "Vous avez obtenu le badge « {$template->nom} » !",
                $template->icon,
                $template->color,
                ['badge_id' => $badge->id, 'badge_nom' => $template->nom]
            );
        }
    }
}
