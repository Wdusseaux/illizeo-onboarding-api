<?php

namespace App\Observers;

use App\Models\Collaborateur;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * On Collaborateur create:
 *  - Auto-fill `initials` from prénom+nom if missing (avoids empty avatars)
 *  - Auto-fill `couleur` with a deterministic palette pick if missing
 *  - Assign Spatie role `collaborateur` to the linked User
 *
 * The first two run BEFORE save (`creating`); the role sync runs after (`created`)
 * since it depends on the persisted record's email/user_id.
 */
class CollaborateurObserver
{
    /** Stable palette used to colour collab avatars when none is set. */
    private const PALETTE = ['#E91E8C', '#1A73E8', '#4CAF50', '#F9A825', '#7B5EA7', '#00897B', '#E53935', '#9C27B0'];

    public function creating(Collaborateur $collab): void
    {
        $this->autoFillCosmetic($collab);
    }

    public function updating(Collaborateur $collab): void
    {
        // If a name was changed but initials still match the old one (or empty), refresh
        if ($collab->isDirty(['prenom', 'nom']) && empty($collab->initials)) {
            $this->autoFillCosmetic($collab);
        }
    }

    public function created(Collaborateur $collab): void
    {
        $this->syncRole($collab);
    }

    public function updated(Collaborateur $collab): void
    {
        // If user_id changed (e.g. linking to an account post-creation), re-sync
        if ($collab->wasChanged('user_id') || $collab->wasChanged('email')) {
            $this->syncRole($collab);
        }
    }

    private function autoFillCosmetic(Collaborateur $collab): void
    {
        if (empty($collab->initials)) {
            $first = $collab->prenom ? mb_substr($collab->prenom, 0, 1) : '';
            $last = $collab->nom ? mb_substr($collab->nom, 0, 1) : '';
            $initials = mb_strtoupper($first . $last);
            if ($initials !== '') $collab->initials = $initials;
        }
        if (empty($collab->couleur)) {
            // Deterministic colour from email/name hash so the same person always
            // gets the same colour across re-creates
            $seed = $collab->email ?: ($collab->prenom . $collab->nom);
            $idx = $seed ? (crc32($seed) % count(self::PALETTE)) : 0;
            $collab->couleur = self::PALETTE[$idx];
        }
    }

    private function syncRole(Collaborateur $collab): void
    {
        $user = $collab->user_id
            ? User::find($collab->user_id)
            : ($collab->email ? User::where('email', $collab->email)->first() : null);
        if (!$user) return;

        // Make sure the role exists for the current tenant guard
        Role::findOrCreate('collaborateur', 'web');
        if (!$user->hasRole('collaborateur')) {
            $user->assignRole('collaborateur');
        }
    }
}
