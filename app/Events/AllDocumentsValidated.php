<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AllDocumentsValidated
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
    ) {}
}
