<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PeriodeEssaiTerminee
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
    ) {}
}
