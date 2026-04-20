<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class DocumentValidated
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $collaborateurName = '',
        public string $contextLabel = '',
    ) {}
}
