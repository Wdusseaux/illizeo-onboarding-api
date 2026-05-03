<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired on a collaborateur's personal birthday
 * (date_naissance day/month == today day/month).
 */
class AnniversairePersonnel
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $collaborateurName = '',
        public string $contextLabel = "Anniversaire personnel",
    ) {}
}
