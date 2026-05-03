<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired 60 days before the end of a CDD contract
 * (date_fin_contrat - 60 days == today AND type_contrat = 'CDD').
 */
class RenouvellementCDD
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $collaborateurName = '',
        public string $contextLabel = "Renouvellement CDD à anticiper",
    ) {}
}
