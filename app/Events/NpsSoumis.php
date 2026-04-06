<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class NpsSoumis
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public int $score,
        public string $parcoursName,
    ) {}
}
