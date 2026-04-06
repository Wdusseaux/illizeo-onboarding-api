<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class NewCollaborateur
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $collaborateurName,
    ) {}
}
