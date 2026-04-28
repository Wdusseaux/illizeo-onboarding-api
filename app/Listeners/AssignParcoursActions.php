<?php

namespace App\Listeners;

use App\Events\ParcoursCreated;
use App\Models\Action;
use App\Models\Collaborateur;
use App\Models\CollaborateurAction;

/**
 * When a parcours is assigned to a collaborateur (creation OR update flipping
 * parcours_id), instantiate a CollaborateurAction row for every Action of that
 * parcours. Idempotent via firstOrCreate so re-firing the event is safe.
 *
 * Without this, the actions are visible (rendered from the Action templates) but
 * have no assignment row — so the leaderboard XP and admin progression queries
 * undercount work, and there's no per-collab "started_at" / "note" surface.
 */
class AssignParcoursActions
{
    public function handle(ParcoursCreated $event): void
    {
        $collab = Collaborateur::find($event->collaborateurId);
        if (!$collab || !$collab->parcours_id) return;

        // Step 1: clean up assignments tied to OTHER parcours (the collab just
        // changed parcours, the old actions shouldn't pollute their checklist).
        // We only purge "a_faire" rows — completed actions stay so progression
        // history and leaderboard XP are preserved.
        $newParcoursActionIds = Action::where('parcours_id', $collab->parcours_id)->pluck('id')->all();
        CollaborateurAction::where('collaborateur_id', $collab->id)
            ->where('status', 'a_faire')
            ->whereNotIn('action_id', $newParcoursActionIds ?: [0])
            ->delete();

        // Step 2: instantiate any missing rows for the new parcours (idempotent)
        foreach ($newParcoursActionIds as $actionId) {
            CollaborateurAction::firstOrCreate(
                ['collaborateur_id' => $collab->id, 'action_id' => $actionId],
                ['status' => 'a_faire']
            );
        }
    }
}
