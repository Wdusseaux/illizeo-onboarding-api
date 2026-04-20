<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteWorkflowSteps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $workflowId,
        public string $tenantId,
        public string $eventClass,
        public array $eventData,
        public int $startFromStep,
    ) {}

    public function handle(): void
    {
        // Initialize tenant context
        $tenant = \App\Models\Tenant::find($this->tenantId);
        if (!$tenant) return;
        tenancy()->initialize($tenant);

        $workflow = Workflow::find($this->workflowId);
        if (!$workflow || !$workflow->actif) return;

        $steps = $workflow->steps ?? [];
        if (empty($steps) || $this->startFromStep >= count($steps)) return;

        // Reconstruct event from stored data
        $event = WorkflowEngine::reconstructEvent($this->eventClass, $this->eventData);
        if (!$event) return;

        Log::info("Executing delayed workflow steps", [
            'workflow' => $workflow->nom,
            'from_step' => $this->startFromStep,
            'total_steps' => count($steps),
        ]);

        // Execute remaining steps starting from startFromStep
        WorkflowEngine::executeStepsFrom($workflow, $event, $this->startFromStep);
    }
}
