<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\CollaborateurAccompagnant;
use App\Models\OnboardingTeam;
use App\Models\OnboardingTeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingTeamController extends Controller
{
    // ─── Teams CRUD ─────────────────────────────────────────

    public function index(): JsonResponse
    {
        $teams = OnboardingTeam::with('members.user:id,name,email')->get();
        return response()->json($teams);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string',
            'description' => 'nullable|string',
            'site' => 'nullable|string',
            'departement' => 'nullable|string',
            'members' => 'nullable|array',
            'members.*.user_id' => 'exists:users,id',
            'members.*.role' => 'required|string',
        ]);

        $team = OnboardingTeam::create($validated);

        if (!empty($validated['members'])) {
            foreach ($validated['members'] as $m) {
                OnboardingTeamMember::create(['team_id' => $team->id, 'user_id' => $m['user_id'], 'role' => $m['role']]);
            }
        }

        return response()->json($team->load('members.user:id,name,email'), 201);
    }

    public function update(Request $request, OnboardingTeam $onboardingTeam): JsonResponse
    {
        $onboardingTeam->update($request->validate([
            'nom' => 'sometimes|string',
            'description' => 'nullable|string',
            'site' => 'nullable|string',
            'departement' => 'nullable|string',
            'actif' => 'nullable|boolean',
        ]));

        if ($request->has('members')) {
            $onboardingTeam->members()->delete();
            foreach ($request->members as $m) {
                OnboardingTeamMember::create(['team_id' => $onboardingTeam->id, 'user_id' => $m['user_id'], 'role' => $m['role']]);
            }
        }

        return response()->json($onboardingTeam->load('members.user:id,name,email'));
    }

    public function destroy(OnboardingTeam $onboardingTeam): JsonResponse
    {
        $onboardingTeam->delete();
        return response()->json(null, 204);
    }

    // ─── Assign team to collaborateur ───────────────────────

    public function assignTeam(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        $request->validate(['team_id' => 'required|exists:onboarding_teams,id']);

        $team = OnboardingTeam::with('members')->findOrFail($request->team_id);

        // Clear existing assignments from this team
        CollaborateurAccompagnant::where('collaborateur_id', $collaborateur->id)->where('team_id', $team->id)->delete();

        // Assign each team member
        foreach ($team->members as $member) {
            CollaborateurAccompagnant::updateOrCreate(
                ['collaborateur_id' => $collaborateur->id, 'role' => $member->role],
                ['user_id' => $member->user_id, 'team_id' => $team->id]
            );
        }

        return response()->json(['message' => "Équipe '{$team->nom}' assignée", 'count' => $team->members->count()]);
    }

    public function assignIndividual(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:manager,hrbp,buddy,it,recruteur,admin_rh,other',
        ]);

        CollaborateurAccompagnant::updateOrCreate(
            ['collaborateur_id' => $collaborateur->id, 'role' => $request->role],
            ['user_id' => $request->user_id]
        );

        return response()->json(['message' => 'Accompagnant assigné']);
    }

    public function removeAccompagnant(CollaborateurAccompagnant $collaborateurAccompagnant): JsonResponse
    {
        $collaborateurAccompagnant->delete();
        return response()->json(null, 204);
    }

    public function collabAccompagnants(Collaborateur $collaborateur): JsonResponse
    {
        $accompagnants = CollaborateurAccompagnant::where('collaborateur_id', $collaborateur->id)
            ->with('user:id,name,email')
            ->get();
        return response()->json($accompagnants);
    }

    // ─── Workload stats ─────────────────────────────────────

    public function workload(): JsonResponse
    {
        $stats = CollaborateurAccompagnant::selectRaw('user_id, role, COUNT(*) as count')
            ->groupBy('user_id', 'role')
            ->with('user:id,name,email')
            ->get();

        return response()->json($stats);
    }
}
