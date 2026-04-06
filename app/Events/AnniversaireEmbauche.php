<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AnniversaireEmbauche
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public int $years,
    ) {}
}
