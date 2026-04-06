<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CollaborateurEnRetard
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public int $progression,
        public int $expectedProgression,
    ) {}
}
