<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SignatureDocument;
use App\Models\DocumentAcknowledgement;
use App\Models\Action;
use App\Models\Collaborateur;
use App\Models\CollaborateurAction;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignatureDocumentController extends Controller
{
    public function index(): JsonResponse
    {
        $docs = SignatureDocument::withCount([
            'acknowledgements as total_envois',
            'acknowledgements as total_signes' => fn ($q) => $q->whereIn('statut', ['lu', 'signe']),
        ])->orderByDesc('created_at')->get();

        return response()->json($docs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:lecture,signature',
            'obligatoire' => 'nullable|boolean',
            'actif' => 'nullable|boolean',
        ]);

        $doc = SignatureDocument::create($validated);

        return response()->json($doc, 201);
    }

    public function update(Request $request, SignatureDocument $signatureDocument): JsonResponse
    {
        $signatureDocument->update($request->validate([
            'titre' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:lecture,signature',
            'obligatoire' => 'nullable|boolean',
            'actif' => 'nullable|boolean',
        ]));

        return response()->json($signatureDocument);
    }

    public function destroy(SignatureDocument $signatureDocument): JsonResponse
    {
        $signatureDocument->delete();
        return response()->json(null, 204);
    }

    /**
     * Stream the underlying PDF/Doc file. Authorization:
     *  - admin/RH (documents:edit) can always view
     *  - any collaborateur of this tenant can view active documents (myAll lists them all)
     *  - inactive documents require existing acknowledgement (audit trail access)
     * `?inline=1` serves with Content-Disposition: inline (browser preview).
     */
    public function viewFile(Request $request, SignatureDocument $signatureDocument)
    {
        if (!$signatureDocument->fichier_path || !Storage::disk('local')->exists($signatureDocument->fichier_path)) {
            abort(404, 'Document file not found.');
        }

        $user = $request->user();
        $isAdmin = $user->hasModulePermission('documents', 'edit');
        if (!$isAdmin) {
            $myCollab = Collaborateur::where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->first();
            if (!$myCollab) {
                abort(403, 'Not a collaborateur.');
            }
            // Active documents: any collaborateur can view (consistent with myAll listing).
            // Inactive documents: require an existing acknowledgement to preserve audit-trail access.
            if (!$signatureDocument->actif) {
                $hasAck = DocumentAcknowledgement::where('signature_document_id', $signatureDocument->id)
                    ->where('collaborateur_id', $myCollab->id)
                    ->exists();
                if (!$hasAck) {
                    abort(403, 'No acknowledgement for this archived document.');
                }
            }
        }

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';
        $filename = $signatureDocument->fichier_nom ?: basename($signatureDocument->fichier_path);
        $mime = Storage::disk('local')->mimeType($signatureDocument->fichier_path) ?: 'application/octet-stream';

        return Storage::disk('local')->response($signatureDocument->fichier_path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.addslashes($filename).'"',
        ]);
    }

    public function uploadFile(Request $request, SignatureDocument $signatureDocument): JsonResponse
    {
        $request->validate(['fichier' => 'required|file|max:10240|mimes:pdf,doc,docx']);

        $file = $request->file('fichier');
        $newPath = $file->store('signature-documents', 'local');

        // Delete the previous file from disk so we don't leak storage on each replace.
        $previousPath = $signatureDocument->fichier_path;
        if ($previousPath && $previousPath !== $newPath && Storage::disk('local')->exists($previousPath)) {
            Storage::disk('local')->delete($previousPath);
        }

        $isReplacement = (bool) $previousPath;
        $newVersion = $isReplacement ? ((int) $signatureDocument->version) + 1 : (int) ($signatureDocument->version ?: 1);

        $signatureDocument->update([
            'fichier_path' => $newPath,
            'fichier_nom' => $file->getClientOriginalName(),
            'version' => $newVersion,
        ]);

        // On version bump: invalidate every previous signature/lecture by creating
        // a new pending ack for each unique (collaborateur, user) that already had
        // a signed/lu ack. We keep the historical rows untouched as proof of past
        // signatures (audit trail, RGPD compliance).
        $invalidated = 0;
        if ($isReplacement) {
            $latestPerCollab = DocumentAcknowledgement::query()
                ->where('signature_document_id', $signatureDocument->id)
                ->whereIn('statut', ['signe', 'lu'])
                ->orderByDesc('signed_at')
                ->get()
                ->groupBy('collaborateur_id');

            foreach ($latestPerCollab as $collabId => $rows) {
                $latest = $rows->first();
                // Only create a new pending ack if the most recent state is still "signed"
                // (no en_attente row already in flight for this user).
                $alreadyPending = DocumentAcknowledgement::where('signature_document_id', $signatureDocument->id)
                    ->where('collaborateur_id', $collabId)
                    ->where('statut', 'en_attente')
                    ->where('id', '>', $latest->id)
                    ->exists();
                if ($alreadyPending) continue;

                DocumentAcknowledgement::create([
                    'signature_document_id' => $signatureDocument->id,
                    'collaborateur_id' => $collabId,
                    'user_id' => $latest->user_id,
                    'statut' => 'en_attente',
                ]);
                $invalidated++;
            }
        }

        return response()->json([
            'message' => $isReplacement ? "Fichier remplacé (v$newVersion)" : 'Fichier uploadé',
            'filename' => $file->getClientOriginalName(),
            'replaced' => $isReplacement,
            'version' => $newVersion,
            'reset_count' => $invalidated,
        ]);
    }

    // Send document to a collaborateur for signature/acknowledgement
    public function sendTo(Request $request, SignatureDocument $signatureDocument): JsonResponse
    {
        $request->validate(['collaborateur_id' => 'required|exists:collaborateurs,id']);

        $existing = DocumentAcknowledgement::where('signature_document_id', $signatureDocument->id)
            ->where('collaborateur_id', $request->collaborateur_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Document déjà envoyé à ce collaborateur'], 422);
        }

        $ack = DocumentAcknowledgement::create([
            'signature_document_id' => $signatureDocument->id,
            'collaborateur_id' => $request->collaborateur_id,
            'user_id' => auth()->id(),
            'statut' => 'en_attente',
        ]);

        // Notify the collaborateur
        $collab = \App\Models\Collaborateur::find($request->collaborateur_id);
        if ($collab && $collab->user_id) {
            $typeLabel = $signatureDocument->type === 'lecture' ? 'à lire et accepter' : 'à signer';
            NotificationService::send($collab->user_id, 'document',
                'Document ' . $typeLabel,
                "Le document « {$signatureDocument->titre} » vous a été envoyé.",
                'file-signature', '#C2185B');
        }

        return response()->json($ack->load('document'), 201);
    }

    // Send to all active collaborateurs
    public function sendToAll(SignatureDocument $signatureDocument): JsonResponse
    {
        $collabs = \App\Models\Collaborateur::all();
        $sent = 0;

        foreach ($collabs as $collab) {
            $exists = DocumentAcknowledgement::where('signature_document_id', $signatureDocument->id)
                ->where('collaborateur_id', $collab->id)
                ->exists();

            if (!$exists) {
                DocumentAcknowledgement::create([
                    'signature_document_id' => $signatureDocument->id,
                    'collaborateur_id' => $collab->id,
                    'user_id' => auth()->id(),
                    'statut' => 'en_attente',
                ]);
                $sent++;
            }
        }

        return response()->json(['message' => "{$sent} envoi(s) effectué(s)"]);
    }

    // Collaborateur acknowledges (reads or signs)
    public function acknowledge(Request $request, DocumentAcknowledgement $acknowledgement): JsonResponse
    {
        $statut = $acknowledgement->document->type === 'lecture' ? 'lu' : 'signe';

        $acknowledgement->update([
            'statut' => $statut,
            'signed_at' => now(),
            'signed_version' => (int) ($acknowledgement->document->version ?: 1),
            'ip_address' => $request->ip(),
            'commentaire' => $request->commentaire,
        ]);

        // Auto-complete any linked signature action for this collaborateur
        $docId = $acknowledgement->signature_document_id;
        $collabId = $acknowledgement->collaborateur_id;
        $linkedActions = Action::where('options->signature_document_id', $docId)->pluck('id');
        if ($linkedActions->isNotEmpty()) {
            CollaborateurAction::where('collaborateur_id', $collabId)
                ->whereIn('action_id', $linkedActions)
                ->where('status', '!=', 'termine')
                ->update(['status' => 'termine', 'completed_at' => now()]);
        }

        return response()->json($acknowledgement->load('document'));
    }

    // Collaborateur refuses
    public function refuse(Request $request, DocumentAcknowledgement $acknowledgement): JsonResponse
    {
        $acknowledgement->update([
            'statut' => 'refuse',
            'commentaire' => $request->commentaire,
        ]);

        return response()->json($acknowledgement);
    }

    // Get acknowledgements for a specific document
    public function acknowledgements(SignatureDocument $signatureDocument): JsonResponse
    {
        return response()->json(
            DocumentAcknowledgement::where('signature_document_id', $signatureDocument->id)
                ->with(['collaborateur', 'user'])
                ->orderByDesc('created_at')
                ->get()
        );
    }

    // Get documents pending for current user (employee side)
    public function myPending(): JsonResponse
    {
        $userId = auth()->id();
        $collabId = \App\Models\Collaborateur::where('user_id', $userId)->value('id');
        if (!$collabId) return response()->json([]);

        return response()->json(
            DocumentAcknowledgement::where('collaborateur_id', $collabId)
                ->where('statut', 'en_attente')
                ->with('document')
                ->get()
        );
    }

    /**
     * GET /me/signature-documents
     * Returns ALL active SignatureDocuments enriched with the current user's
     * acknowledgement status (signé / à signer / à lire). Used by the employee
     * Documents page to show the full list, not only what's pending.
     */
    /**
     * Full signature history for the current user (all completed acks across all
     * versions). Used by the "Mes signatures" employee page so users keep the
     * audit trail visible even after a document has been re-uploaded.
     */
    public function myHistory(): JsonResponse
    {
        $userId = auth()->id();
        $collab = \App\Models\Collaborateur::where('user_id', $userId)->first();
        if (!$collab) return response()->json([]);

        $rows = DocumentAcknowledgement::with('document')
            ->where('collaborateur_id', $collab->id)
            ->whereIn('statut', ['signe', 'lu'])
            ->orderByDesc('signed_at')
            ->get();

        return response()->json($rows->map(function ($a) {
            return [
                'ack_id' => $a->id,
                'document_id' => $a->signature_document_id,
                'document_title' => $a->document?->titre,
                'document_description' => $a->document?->description,
                'document_type' => $a->document?->type,
                'document_current_version' => (int) ($a->document?->version ?: 1),
                'statut' => $a->statut,
                'signed_at' => $a->signed_at,
                'signed_version' => $a->signed_version,
                'is_outdated' => $a->signed_version !== null && $a->document && $a->signed_version < (int) $a->document->version,
                'ip_address' => $a->ip_address,
            ];
        }));
    }

    public function myAll(): JsonResponse
    {
        $userId = auth()->id();
        $collab = \App\Models\Collaborateur::where('user_id', $userId)->first();
        $collabId = $collab?->id;

        $docs = SignatureDocument::where('actif', true)->orderBy('id')->get();
        // Latest ack per (signature_document_id, collaborateur) — version-aware:
        // a re-uploaded document may have multiple ack rows (old signed v1 + new pending v2).
        $latestAcks = $collabId
            ? DocumentAcknowledgement::where('collaborateur_id', $collabId)
                ->orderByDesc('id')
                ->get()
                ->groupBy('signature_document_id')
                ->map(fn ($group) => $group->first())
            : collect();

        return response()->json($docs->map(function ($d) use ($latestAcks) {
            $ack = $latestAcks->get($d->id);
            $statut = $ack?->statut ?? 'en_attente';
            $isCompleted = in_array($statut, ['signe', 'lu'], true);
            $uiStatus = $statut === 'signe' ? 'signé'
                : ($statut === 'lu' ? 'lu'
                : ($d->type === 'lecture' ? 'à lire' : 'à signer'));
            return [
                'id' => $d->id,
                'name' => $d->titre,
                'description' => $d->description,
                'type' => $d->type,
                'obligatoire' => $d->obligatoire,
                'version' => (int) ($d->version ?: 1),
                'status' => $uiStatus,
                'urgent' => $d->obligatoire && !$isCompleted,
                'signed_at' => $ack?->signed_at,
                'signed_version' => $ack?->signed_version,
                'ack_id' => $ack?->id,
            ];
        }));
    }

    // Get or create acknowledgement for a specific document + current user
    public function myAcknowledgement(SignatureDocument $signatureDocument): JsonResponse
    {
        $userId = auth()->id();
        $collab = \App\Models\Collaborateur::where('user_id', $userId)->first();
        if (!$collab) return response()->json(['error' => 'No collaborateur'], 404);

        $ack = DocumentAcknowledgement::firstOrCreate(
            ['signature_document_id' => $signatureDocument->id, 'collaborateur_id' => $collab->id],
            ['user_id' => $userId, 'statut' => 'en_attente']
        );

        return response()->json($ack->load('document'));
    }
}
