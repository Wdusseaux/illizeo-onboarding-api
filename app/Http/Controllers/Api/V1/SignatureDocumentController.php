<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SignatureDocument;
use App\Models\DocumentAcknowledgement;
use App\Models\Action;
use App\Models\CollaborateurAction;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignatureDocumentController extends Controller
{
    public function index(): JsonResponse
    {
        $docs = SignatureDocument::withCount([
            'logs as total_envois',
            'logs as total_signes' => fn ($q) => $q->whereIn('statut', ['lu', 'signe']),
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

    public function uploadFile(Request $request, SignatureDocument $signatureDocument): JsonResponse
    {
        $request->validate(['fichier' => 'required|file|max:10240|mimes:pdf,doc,docx']);

        $file = $request->file('fichier');
        $path = $file->store('signature-documents', 'local');

        $signatureDocument->update([
            'fichier_path' => $path,
            'fichier_nom' => $file->getClientOriginalName(),
        ]);

        return response()->json(['message' => 'Fichier uploadé', 'filename' => $file->getClientOriginalName()]);
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
