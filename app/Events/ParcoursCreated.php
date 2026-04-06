<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ParcoursCreated
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $parcoursName,
    ) {}
}
