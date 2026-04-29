<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\NewCollaborateur;
use App\Events\ParcoursCompleted;
use App\Events\ParcoursCreated;
use App\Http\Controllers\Controller;
use App\Mail\NotificationMail;
use App\Models\Collaborateur;
use App\Models\Parcours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CollaborateurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Collaborateur::with(['parcours', 'groupes', 'manager:id,prenom,nom', 'hrManager:id,prenom,nom']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('site')) {
            $query->where('site', $request->site);
        }
        if ($request->has('departement')) {
            $query->where('departement', $request->departement);
        }
        if ($request->has('parcours_id')) {
            $query->where('parcours_id', $request->parcours_id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prenom' => 'required|string|max:255',
            'nom' => 'required|string|max:255',
            'email' => 'required|email|unique:collaborateurs,email',
            'poste' => 'nullable|string',
            'site' => 'nullable|string',
            'departement' => 'nullable|string',
            'date_debut' => 'nullable|date',
            'parcours_id' => 'nullable|exists:parcours,id',
            'manager_id' => 'nullable|integer|exists:collaborateurs,id',
            'hr_manager_id' => 'nullable|integer|exists:collaborateurs,id',
        ]);

        $validated['initials'] = strtoupper(mb_substr($validated['prenom'], 0, 1) . mb_substr($validated['nom'], 0, 1));
        $collaborateur = Collaborateur::create($validated);

        NewCollaborateur::dispatch(
            $collaborateur->id,
            "{$collaborateur->prenom} {$collaborateur->nom}"
        );

        // Fire ParcoursCreated if a parcours was assigned
        if (!empty($validated['parcours_id'])) {
            $parcours = Parcours::find($validated['parcours_id']);
            if ($parcours) {
                ParcoursCreated::dispatch($collaborateur->id, $parcours->nom);
            }
        }

        return response()->json($collaborateur, 201);
    }

    public function show(Collaborateur $collaborateur): JsonResponse
    {
        return response()->json(
            $collaborateur->load(['parcours.categorie', 'groupes', 'documents.categorie', 'manager:id,prenom,nom', 'hrManager:id,prenom,nom'])
        );
    }

    public function update(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        $validated = $request->validate([
            'prenom' => 'sometimes|string|max:255',
            'nom' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:collaborateurs,email,' . $collaborateur->id,
            'poste' => 'nullable|string',
            'site' => 'nullable|string',
            'departement' => 'nullable|string',
            'date_debut' => 'nullable|date',
            'phase' => 'nullable|string',
            'progression' => 'nullable|integer|min:0|max:100',
            'status' => 'nullable|in:en_cours,en_retard,termine',
            'parcours_id' => 'nullable|exists:parcours,id',
            'manager_id' => 'nullable|integer|exists:collaborateurs,id',
            'hr_manager_id' => 'nullable|integer|exists:collaborateurs,id',
        ]);

        // Circular manager check
        if (isset($validated['manager_id']) && $validated['manager_id']) {
            if ((int) $validated['manager_id'] === $collaborateur->id) {
                return response()->json(['error' => 'Un collaborateur ne peut pas être son propre manager'], 422);
            }
            // Walk up the chain to detect loops
            $visited = [$collaborateur->id];
            $current = (int) $validated['manager_id'];
            while ($current) {
                if (in_array($current, $visited)) {
                    return response()->json(['error' => 'Boucle détectée dans la hiérarchie managériale'], 422);
                }
                $visited[] = $current;
                $parent = \App\Models\Collaborateur::find($current);
                $current = $parent?->manager_id ? (int) $parent->manager_id : 0;
            }
        }

        $previousProgression = $collaborateur->progression;
        $previousParcoursId = $collaborateur->parcours_id;
        $collaborateur->update($validated);

        // Fire ParcoursCompleted when progression reaches 100%
        if (isset($validated['progression']) && (int) $validated['progression'] === 100 && (int) $previousProgression !== 100) {
            $parcoursName = $collaborateur->parcours?->nom ?? 'Parcours';
            ParcoursCompleted::dispatch($collaborateur->id, $parcoursName);
            if ($collaborateur->user_id) {
                \App\Services\BadgeAutoAwardService::checkAndAward($collaborateur->user_id, 'parcours_termine', $collaborateur->id);
            }
        }

        // Fire ParcoursCreated when parcours_id is newly assigned
        if (isset($validated['parcours_id']) && $validated['parcours_id'] != $previousParcoursId) {
            $parcours = Parcours::find($validated['parcours_id']);
            if ($parcours) {
                ParcoursCreated::dispatch($collaborateur->id, $parcours->nom);
            }
        }

        return response()->json($collaborateur);
    }

    public function destroy(Collaborateur $collaborateur): JsonResponse
    {
        $collaborateur->delete();
        return response()->json(null, 204);
    }

    /**
     * Purge demo/seed data: delete all collaborateurs with @illizeo.com emails.
     */
    public function purgeDemo(): JsonResponse
    {
        // Delete all demo employee data but keep configuration
        $collabIds = Collaborateur::pluck('id');

        // Identify non-admin users to delete
        $adminUserIds = \App\Models\User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin_rh', 'admin']))->pluck('id');
        $demoUserIds = \App\Models\User::whereNotIn('id', $adminUserIds)->pluck('id');

        // Purge employee-linked data
        \App\Models\CollaborateurAction::whereIn('collaborateur_id', $collabIds)->delete();
        \App\Models\CollaborateurAccompagnant::whereIn('collaborateur_id', $collabIds)->delete();
        \App\Models\DocumentAcknowledgement::whereIn('collaborateur_id', $collabIds)->delete();

        // Only delete messages/conversations/notifications involving demo users
        \App\Models\Message::whereIn('sender_id', $demoUserIds)->delete();
        \App\Models\Conversation::where(function ($q) use ($demoUserIds) {
            $q->whereIn('user_a_id', $demoUserIds)->orWhereIn('user_b_id', $demoUserIds);
        })->delete();
        \App\Models\UserNotification::whereIn('user_id', $demoUserIds)->delete();

        // Delete demo collaborateurs
        $deleted = Collaborateur::delete();

        // Delete non-admin users
        \App\Models\User::whereIn('id', $demoUserIds)->delete();

        // Set demo_mode to false
        \App\Models\CompanySetting::updateOrCreate(['key' => 'demo_mode'], ['value' => 'false']);

        return response()->json(['deleted_collaborateurs' => $deleted, 'demo_mode' => false]);
    }

    /**
     * Send a manual reminder email to a collaborateur (relance RH).
     */
    public function relancer(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        if (empty($collaborateur->email)) {
            return response()->json(['success' => false, 'message' => "Le collaborateur n'a pas d'adresse email."], 422);
        }

        $recipientName = trim(($collaborateur->prenom ?? '').' '.($collaborateur->nom ?? '')) ?: 'Collaborateur';
        $bodyHtml = nl2br(e($data['body']));

        try {
            Mail::to($collaborateur->email)->send(new NotificationMail(
                recipientName: $recipientName,
                emailSubject: $data['subject'],
                heading: $data['subject'],
                body: $bodyHtml,
            ));
        } catch (\Throwable $e) {
            Log::error('Relance email failed', ['collab_id' => $collaborateur->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => "Échec de l'envoi : ".$e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Relance envoyée à {$recipientName}.",
        ]);
    }
}
