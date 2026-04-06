<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class DeadlineApproaching
{
    use Dispatchable;

    public function __construct(
        public int $collaborateurId,
        public string $actionTitle,
        public string $deadline,
    ) {}
}
