<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ActionCompleted;
use App\Events\FormulaireSubmitted;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Collaborateur;
use App\Models\CollaborateurAction;
use App\Models\Groupe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollaborateurActionController extends Controller
{
    /**
     * List assigned actions for a collaborateur
     */
    public function forCollaborateur(Collaborateur $collaborateur): JsonResponse
    {
        $assignments = CollaborateurAction::where('collaborateur_id', $collaborateur->id)
            ->with(['action.actionType', 'action.phase'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * List all assignments for an action (who's assigned)
     */
    public function forAction(Action $action): JsonResponse
    {
        $assignments = CollaborateurAction::where('action_id', $action->id)
            ->with('collaborateur')
            ->get();

        return response()->json($assignments);
    }

    /**
     * Assign action(s) to collaborateur(s)
     * Supports: individual, group, all, site, departement, contrat
     */
    public function assign(Request $request): JsonResponse
    {
        $request->validate([
            'action_ids' => 'required|array',
            'action_ids.*' => 'exists:actions,id',
            'mode' => 'required|in:tous,individuel,groupe,site,departement,contrat',
            'valeurs' => 'nullable|array',
        ]);

        $actionIds = $request->action_ids;
        $mode = $request->mode;
        $valeurs = $request->valeurs ?? [];

        // Resolve collaborateurs based on mode
        $collabQuery = Collaborateur::query();

        switch ($mode) {
            case 'tous':
                break; // All collaborateurs
            case 'individuel':
                $collabQuery->whereIn('id', $valeurs);
                break;
            case 'groupe':
                $groupeIds = Groupe::whereIn('nom', $valeurs)->pluck('id');
                $collabIds = \DB::table('collaborateur_groupe')
                    ->whereIn('groupe_id', $groupeIds)
                    ->pluck('collaborateur_id');
                $collabQuery->whereIn('id', $collabIds);
                break;
            case 'site':
                $collabQuery->whereIn('site', $valeurs);
                break;
            case 'departement':
                $collabQuery->whereIn('departement', $valeurs);
                break;
            case 'contrat':
                $collabQuery->whereIn('type_contrat', $valeurs);
                break;
        }

        $collaborateurs = $collabQuery->get();
        $created = 0;

        foreach ($collaborateurs as $collab) {
            foreach ($actionIds as $actionId) {
                CollaborateurAction::firstOrCreate(
                    ['collaborateur_id' => $collab->id, 'action_id' => $actionId],
                    ['status' => 'a_faire']
                );
                $created++;
            }
        }

        // Recalculate progression for all affected collaborateurs
        foreach ($collaborateurs as $collab) {
            $this->recalculateProgression($collab->id);
        }

        return response()->json([
            'message' => "{$created} assignation(s) créée(s)",
            'collaborateurs_count' => $collaborateurs->count(),
            'actions_count' => count($actionIds),
        ]);
    }

    /**
     * Update status of an assignment
     */
    public function updateStatus(Request $request, CollaborateurAction $collaborateurAction): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:a_faire,en_cours,termine,annule',
            'note' => 'nullable|string',
            'response_data' => 'nullable|array',
        ]);

        $data = ['status' => $request->status];

        if ($request->status === 'en_cours' && !$collaborateurAction->started_at) {
            $data['started_at'] = now();
        }
        if ($request->status === 'termine') {
            $data['completed_at'] = now();
        }
        if ($request->has('note')) $data['note'] = $request->note;
        if ($request->has('response_data')) $data['response_data'] = $request->response_data;

        $collaborateurAction->update($data);

        if ($request->status === 'termine') {
            $collaborateurAction->load(['collaborateur', 'action.actionType']);
            if ($collaborateurAction->collaborateur && $collaborateurAction->action) {
                ActionCompleted::dispatch(
                    $collaborateurAction->collaborateur_id,
                    $collaborateurAction->action->titre
                );
                $typeName = strtolower($collaborateurAction->action->actionType?->nom ?? '');
                if ($typeName === 'formation' && $collaborateurAction->collaborateur->user_id) {
                    \App\Services\BadgeAutoAwardService::checkAndAward(
                        $collaborateurAction->collaborateur->user_id,
                        'formation_complete',
                        $collaborateurAction->collaborateur_id
                    );
                }
            }
        }

        // Auto-calculate progression based on completed actions
        $this->recalculateProgression($collaborateurAction->collaborateur_id);

        return response()->json($collaborateurAction->fresh()->load(['action.actionType', 'collaborateur']));
    }

    /**
     * Remove assignment
     */
    public function unassign(CollaborateurAction $collaborateurAction): JsonResponse
    {
        $collabId = $collaborateurAction->collaborateur_id;
        $collaborateurAction->delete();
        $this->recalculateProgression($collabId);
        return response()->json(null, 204);
    }

    /**
     * Recalculate collaborateur progression based on completed actions.
     */
    private function recalculateProgression(int $collaborateurId): void
    {
        $total = CollaborateurAction::where('collaborateur_id', $collaborateurId)->count();
        $completed = CollaborateurAction::where('collaborateur_id', $collaborateurId)
            ->where('status', 'termine')
            ->count();

        $progression = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $collab = Collaborateur::find($collaborateurId);
        if ($collab) {
            $collab->update([
                'progression' => $progression,
                'actions_completes' => $completed,
                'actions_total' => $total,
            ]);
        }
    }

    /**
     * My assigned actions (for logged-in employee)
     */
    public function myActions(Request $request): JsonResponse
    {
        $user = $request->user();
        $collab = Collaborateur::where('user_id', $user->id)->first();

        if (!$collab) {
            return response()->json([]);
        }

        $assignments = CollaborateurAction::where('collaborateur_id', $collab->id)
            ->with(['action.actionType', 'action.phase', 'action.parcours'])
            ->orderByRaw("CASE WHEN status = 'a_faire' THEN 0 WHEN status = 'en_cours' THEN 1 WHEN status = 'termine' THEN 2 ELSE 3 END")
            ->get();

        return response()->json($assignments);
    }

    /**
     * Complete my action (employee marks as done)
     */
    public function completeMyAction(Request $request, CollaborateurAction $collaborateurAction): JsonResponse
    {
        $user = $request->user();
        $collab = Collaborateur::where('user_id', $user->id)->first();

        if (!$collab || $collaborateurAction->collaborateur_id !== $collab->id) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $collaborateurAction->update([
            'status' => 'termine',
            'completed_at' => now(),
            'response_data' => $request->input('response_data'),
        ]);

        $collaborateurAction->load('action.actionType', 'collaborateur');
        if ($collaborateurAction->action) {
            ActionCompleted::dispatch(
                $collaborateurAction->collaborateur_id,
                $collaborateurAction->action->titre
            );

            // Fire FormulaireSubmitted if the action type is a formulaire/questionnaire
            $typeName = $collaborateurAction->action->actionType?->nom ?? '';
            if (in_array(strtolower($typeName), ['formulaire', 'questionnaire'])) {
                FormulaireSubmitted::dispatch(
                    $collaborateurAction->collaborateur_id,
                    $collaborateurAction->action->titre
                );
            }
            if (strtolower($typeName) === 'formation' && $collaborateurAction->collaborateur?->user_id) {
                \App\Services\BadgeAutoAwardService::checkAndAward(
                    $collaborateurAction->collaborateur->user_id,
                    'formation_complete',
                    $collaborateurAction->collaborateur_id
                );
            }

            // TODO: When a dedicated NPS endpoint is created, fire:
            //   NpsSoumis::dispatch($collaborateurId, $score, $parcoursName);
            // For now, NPS questionnaires are handled as regular formulaires above.
        }

        $this->recalculateProgression($collab->id);

        return response()->json($collaborateurAction->fresh());
    }

    /**
     * Reactivate my action (employee marks as not done)
     */
    public function reactivateMyAction(Request $request, CollaborateurAction $collaborateurAction): JsonResponse
    {
        $user = $request->user();
        $collab = Collaborateur::where('user_id', $user->id)->first();

        if (!$collab || $collaborateurAction->collaborateur_id !== $collab->id) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $collaborateurAction->update([
            'status' => 'a_faire',
            'completed_at' => null,
        ]);

        $this->recalculateProgression($collab->id);

        return response()->json($collaborateurAction->fresh());
    }
}
