<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CooptationValidated
{
    use Dispatchable;

    public function __construct(
        public int $cooptationId,
        public string $referrerName,
        public string $candidateName,
    ) {}
}
