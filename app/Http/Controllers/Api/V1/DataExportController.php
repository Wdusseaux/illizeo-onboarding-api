<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\CollaborateurAccompagnant;
use App\Models\CollaborateurAction;
use App\Models\Contrat;
use App\Models\Conversation;
use App\Models\Cooptation;
use App\Models\EmailTemplate;
use App\Models\Integration;
use App\Models\Message;
use App\Models\OnboardingTeam;
use App\Models\Parcours;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExportController extends Controller
{
    /**
     * Export all tenant data as JSON download.
     */
    public function exportAll(): JsonResponse
    {
        $data = [
            'exported_at' => now()->toIso8601String(),
            'collaborateurs' => Collaborateur::with(['assignedActions', 'documents'])->get(),
            'parcours' => Parcours::with(['phases', 'phases.actions'])->get(),
            'users' => User::all()->makeHidden(['password', 'remember_token']),
            'conversations' => Conversation::with('messages')->get(),
            'workflows' => Workflow::all(),
            'email_templates' => EmailTemplate::all(),
            'contrats' => Contrat::all(),
            'integrations' => Integration::all()->map(fn ($i) => array_merge(
                $i->makeVisible('config')->toArray(),
                ['config' => $i->config_safe]
            )),
            'cooptations' => Cooptation::all(),
            'onboarding_teams' => OnboardingTeam::with('members')->get(),
        ];

        $filename = 'export-tenant-' . now()->format('Y-m-d_His') . '.json';

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Export collaborateurs as CSV (streamed).
     */
    public function exportCollaborateurs(): StreamedResponse
    {
        $filename = 'collaborateurs-' . now()->format('Y-m-d_His') . '.csv';

        return new StreamedResponse(function () {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'id', 'prenom', 'nom', 'email', 'poste', 'site',
                'departement', 'date_debut', 'phase', 'progression', 'status',
            ], ';');

            Collaborateur::query()
                ->select(['id', 'prenom', 'nom', 'email', 'poste', 'site', 'departement', 'date_debut', 'phase', 'progression', 'status'])
                ->orderBy('nom')
                ->chunk(200, function ($collaborateurs) use ($handle) {
                    foreach ($collaborateurs as $collab) {
                        fputcsv($handle, [
                            $collab->id,
                            $collab->prenom,
                            $collab->nom,
                            $collab->email,
                            $collab->poste,
                            $collab->site,
                            $collab->departement,
                            $collab->date_debut?->format('Y-m-d'),
                            $collab->phase,
                            $collab->progression,
                            $collab->status,
                        ], ';');
                    }
                });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Export a simple audit log of recent activity.
     */
    public function exportAuditLog(): JsonResponse
    {
        // Export real audit logs if available
        $logs = \App\Models\AuditLog::orderByDesc('created_at')
            ->limit(1000)
            ->get();

        if ($logs->isEmpty()) {
            // Fallback: export entity history
            $users = User::select('id', 'name', 'email', 'created_at', 'updated_at')
                ->orderByDesc('updated_at')->limit(500)->get()
                ->map(fn ($u) => ['type' => 'user', 'id' => $u->id, 'label' => $u->name, 'created_at' => $u->created_at, 'updated_at' => $u->updated_at]);
            $collaborateurs = Collaborateur::select('id', 'prenom', 'nom', 'email', 'created_at', 'updated_at')
                ->orderByDesc('updated_at')->limit(500)->get()
                ->map(fn ($c) => ['type' => 'collaborateur', 'id' => $c->id, 'label' => $c->prenom . ' ' . $c->nom, 'created_at' => $c->created_at, 'updated_at' => $c->updated_at]);
            $records = $users->concat($collaborateurs)->sortByDesc('updated_at')->take(500)->values();
        } else {
            $records = $logs;
        }

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'total' => $records->count(),
            'records' => $records,
        ]);
    }

    /**
     * Export data with optional AES-256 encryption.
     */
    public function exportEncrypted(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $request->validate([
            'type' => 'required|in:all,collaborateurs,audit',
            'password' => 'required|string|min:8',
        ]);

        // Get the data based on type
        $data = match ($request->type) {
            'all' => $this->getAllDataArray(),
            'collaborateurs' => Collaborateur::all()->toArray(),
            'audit' => \App\Models\AuditLog::orderByDesc('created_at')->limit(1000)->get()->toArray(),
        };

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Encrypt with AES-256-CBC
        $key = hash('sha256', $request->password, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $payload = base64_encode($iv . $encrypted);

        AuditLog::log('export_encrypted', null, null, null, "Export chiffré ({$request->type})");

        return response($payload)
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename="illizeo-export-' . $request->type . '-encrypted.aes"');
    }

    private function getAllDataArray(): array
    {
        return [
            'collaborateurs' => Collaborateur::all()->toArray(),
            'parcours' => \App\Models\Parcours::all()->toArray(),
            'actions' => \App\Models\Action::all()->toArray(),
            'users' => User::select('id', 'name', 'email', 'created_at')->get()->toArray(),
            'audit_logs' => \App\Models\AuditLog::orderByDesc('created_at')->limit(500)->get()->toArray(),
        ];
    }

    /**
     * RGPD right to be forgotten — delete all data for a collaborateur by email.
     */
    public function deleteCollaborateurData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $collaborateur = Collaborateur::where('email', $validated['email'])->first();

        if (! $collaborateur) {
            return response()->json(['message' => 'Aucun collaborateur trouvé avec cet email.'], 404);
        }

        DB::transaction(function () use ($collaborateur) {
            // Delete assigned actions
            CollaborateurAction::where('collaborateur_id', $collaborateur->id)->delete();

            // Delete accompagnants links
            CollaborateurAccompagnant::where('collaborateur_id', $collaborateur->id)->delete();

            // Delete messages sent by the associated user
            if ($collaborateur->user_id) {
                Message::where('sender_id', $collaborateur->user_id)->delete();
                UserNotification::where('user_id', $collaborateur->user_id)->delete();
            }

            // Delete the collaborateur record
            $collaborateur->delete();

            // Delete the associated user account if exists
            if ($collaborateur->user_id) {
                User::where('id', $collaborateur->user_id)->delete();
            }
        });

        Log::info('RGPD deletion executed', ['email' => $validated['email']]);

        return response()->json([
            'message' => 'Données du collaborateur supprimées conformément au RGPD.',
            'email' => $validated['email'],
        ]);
    }

    /**
     * Request account deletion (logged, actual deletion is manual/scheduled).
     */
    public function requestAccountDeletion(): JsonResponse
    {
        $user = auth()->user();

        Log::info('RGPD account deletion requested', [
            'user_id' => $user->id,
            'email' => $user->email,
            'requested_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'Votre demande de suppression de compte a été enregistrée. Elle sera traitée sous 30 jours.',
        ]);
    }
}
