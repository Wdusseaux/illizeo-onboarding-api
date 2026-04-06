<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ContratSigned
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $contratName,
    ) {}
}
