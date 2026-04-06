<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class DocumentSubmitted
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $documentName,
        public string $categoryName,
    ) {}
}
