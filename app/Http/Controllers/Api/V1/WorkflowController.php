<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Traits\ChecksPlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    use ChecksPlanLimits;

    public function index(): JsonResponse
    {
        return response()->json(Workflow::all());
    }

    public function store(Request $request): JsonResponse
    {
        $limitCheck = $this->checkPlanLimit('max_workflows', Workflow::count(), 'workflows');
        if ($limitCheck) {
            return $limitCheck;
        }

        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'declencheur' => 'required|string',
            'action' => 'required|string',
            'destinataire' => 'required|string',
            'actif' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
            'steps' => 'nullable|array',
            'target_user_id' => 'nullable|integer|exists:users,id',
            'target_group_id' => 'nullable|integer',
            'badge_name' => 'nullable|string|max:255',
            'badge_icon' => 'nullable|string|max:255',
            'badge_color' => 'nullable|string|max:255',
            'email_subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string',
            'bot_message' => 'nullable|string',
            'translations' => 'nullable|array',
        ]);

        $workflow = Workflow::create($validated);
        return response()->json($workflow, 201);
    }

    public function show(Workflow $workflow): JsonResponse
    {
        return response()->json($workflow);
    }

    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $workflow->update($request->validate([
            'nom' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'declencheur' => 'sometimes|string',
            'action' => 'sometimes|string',
            'destinataire' => 'sometimes|string',
            'actif' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
            'steps' => 'nullable|array',
            'target_user_id' => 'nullable|integer|exists:users,id',
            'target_group_id' => 'nullable|integer',
            'badge_name' => 'nullable|string|max:255',
            'badge_icon' => 'nullable|string|max:255',
            'badge_color' => 'nullable|string|max:255',
            'email_subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string',
            'bot_message' => 'nullable|string',
            'translations' => 'nullable|array',
        ]));

        return response()->json($workflow);
    }

    public function destroy(Workflow $workflow): JsonResponse
    {
        $workflow->delete();
        return response()->json(null, 204);
    }
}
