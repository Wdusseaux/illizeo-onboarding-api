<?php

namespace App\Listeners;

use App\Services\WorkflowEngine;

class WorkflowListener
{
    public function handle(object $event): void
    {
        WorkflowEngine::handle($event);
    }
}
