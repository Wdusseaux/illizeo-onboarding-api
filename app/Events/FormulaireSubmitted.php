<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FormulaireSubmitted
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $formulaireName,
    ) {}
}
