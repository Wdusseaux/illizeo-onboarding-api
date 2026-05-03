<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired 15 days before the end of a collaborateur's période d'essai
 * (date_fin_essai - 15 days == today).
 */
class FinEssaiApproche
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $collaborateurName = '',
        public string $contextLabel = "Fin de période d'essai approche",
    ) {}
}
