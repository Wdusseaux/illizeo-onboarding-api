<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class DocumentRefused
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $documentName,
    ) {}
}
