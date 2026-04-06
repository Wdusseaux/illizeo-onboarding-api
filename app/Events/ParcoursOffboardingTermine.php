<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ParcoursOffboardingTermine
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
    ) {}
}
