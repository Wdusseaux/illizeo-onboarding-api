<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired the morning of a collaborateur's arrival (date_debut == today).
 */
class ArriveeJour
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $collaborateurName = '',
        public string $contextLabel = "Jour d'arrivée",
    ) {}
}
