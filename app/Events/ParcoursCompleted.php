<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ParcoursCompleted
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $parcoursName,
    ) {}
}
