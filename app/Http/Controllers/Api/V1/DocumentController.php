<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\AllDocumentsValidated;
use App\Events\DocumentRefused;
use App\Events\DocumentSubmitted;
use App\Events\DocumentValidated;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentCategorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Document::with(['categorie', 'collaborateur', 'uploadedBy', 'validatedBy']);

        if ($request->has('collaborateur_id')) {
            $query->where('collaborateur_id', $request->collaborateur_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'description' => 'nullable|string',
            'obligatoire' => 'nullable|boolean',
            'type' => 'nullable|in:upload,formulaire',
            'is_template' => 'nullable|boolean',
            'categorie_id' => 'required|exists:document_categories,id',
            'collaborateur_id' => 'nullable|exists:collaborateurs,id',
            'translations' => 'nullable|array',
        ]);

        $document = Document::create($validated);
        return response()->json($document->load('categorie'), 201);
    }

    /**
     * Upload a template file (modèle) for a document template.
     */
    public function uploadTemplate(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $file = $request->file('file');

        // Delete old template file if exists
        if ($document->fichier_modele_path && Storage::disk('local')->exists($document->fichier_modele_path)) {
            Storage::disk('local')->delete($document->fichier_modele_path);
        }

        $path = $file->store('templates', 'local');

        $document->update([
            'fichier_modele_path' => $path,
            'fichier_modele_original' => $file->getClientOriginalName(),
        ]);

        return response()->json($document->fresh('categorie'));
    }

    /**
     * Download a document template file.
     */
    public function downloadTemplate(Document $document): StreamedResponse|JsonResponse
    {
        if (!$document->fichier_modele_path || !Storage::disk('local')->exists($document->fichier_modele_path)) {
            return response()->json(['error' => 'Fichier modèle introuvable'], 404);
        }

        return Storage::disk('local')->download(
            $document->fichier_modele_path,
            $document->fichier_modele_original ?? basename($document->fichier_modele_path)
        );
    }

    /**
     * List all document templates (is_template = true).
     */
    public function templates(): JsonResponse
    {
        $templates = Document::where('is_template', true)
            ->with('categorie')
            ->orderBy('categorie_id')
            ->orderBy('nom')
            ->get();

        return response()->json($templates);
    }

    /**
     * Upload a file for an existing or new document.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10 MB max
            'collaborateur_id' => 'required|exists:collaborateurs,id',
            'categorie_id' => 'nullable|exists:document_categories,id',
            'categorie' => 'nullable|string',
            'nom' => 'required|string',
            'document_id' => 'nullable|exists:documents,id',
        ]);

        $file = $request->file('file');
        $collabId = $request->collaborateur_id;

        // Store in tenant-specific directory
        $path = $file->store("documents/{$collabId}", 'local');

        // If a document_id is provided, update the existing document
        if ($request->document_id) {
            $document = Document::findOrFail($request->document_id);
            // Delete old file if it exists
            if ($document->fichier_path && Storage::disk('local')->exists($document->fichier_path)) {
                Storage::disk('local')->delete($document->fichier_path);
            }
            $document->update([
                'user_id' => auth()->id(),
                'fichier_path' => $path,
                'fichier_original' => $file->getClientOriginalName(),
                'fichier_taille' => $file->getSize(),
                'fichier_mime' => $file->getClientMimeType(),
                'status' => 'soumis',
            ]);
        } else {
            // Create a new document record
            $document = Document::create([
                'collaborateur_id' => $collabId,
                'user_id' => auth()->id(),
                'categorie_id' => $request->categorie_id,
                'nom' => $request->nom,
                'fichier_original' => $file->getClientOriginalName(),
                'fichier_path' => $path,
                'fichier_taille' => $file->getSize(),
                'fichier_mime' => $file->getClientMimeType(),
                'status' => 'soumis',
            ]);
        }

        // Fire event for workflow engine
        $categoryName = $request->categorie ?? ($document->categorie?->nom ?? '');
        DocumentSubmitted::dispatch($collabId, $request->nom, $categoryName);

        return response()->json($document->fresh(['categorie', 'collaborateur', 'uploadedBy']), 201);
    }

    /**
     * Download a document file.
     */
    public function download(Document $document): StreamedResponse|JsonResponse
    {
        if (!$document->fichier_path || !Storage::disk('local')->exists($document->fichier_path)) {
            return response()->json(['error' => 'Fichier introuvable'], 404);
        }

        return Storage::disk('local')->download(
            $document->fichier_path,
            $document->fichier_original ?? basename($document->fichier_path)
        );
    }

    public function show(Document $document): JsonResponse
    {
        return response()->json($document->load(['categorie', 'collaborateur', 'uploadedBy', 'validatedBy']));
    }

    public function update(Request $request, Document $document): JsonResponse
    {
        $previousStatus = $document->status;

        $validated = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'obligatoire' => 'nullable|boolean',
            'type' => 'nullable|in:upload,formulaire',
            'status' => 'nullable|in:manquant,soumis,en_attente,valide,refuse',
            'fichier_path' => 'nullable|string',
            'notes' => 'nullable|string',
            'translations' => 'nullable|array',
        ]);

        $document->update($validated);

        // Fire DocumentSubmitted when status changes to soumis
        if (isset($validated['status']) && $validated['status'] === 'soumis' && $previousStatus !== 'soumis' && $document->collaborateur_id) {
            $categoryName = $document->categorie?->nom ?? '';
            DocumentSubmitted::dispatch($document->collaborateur_id, $document->nom, $categoryName);
        }

        // Fire DocumentRefused when status changes to refuse
        if (isset($validated['status']) && $validated['status'] === 'refuse' && $previousStatus !== 'refuse' && $document->collaborateur_id) {
            DocumentRefused::dispatch($document->collaborateur_id, $document->nom);
        }

        // Fire DocumentValidated when status changes to valide
        if (isset($validated['status']) && $validated['status'] === 'valide' && $previousStatus !== 'valide' && $document->collaborateur_id) {
            DocumentValidated::dispatch($document->collaborateur_id, $document->nom ?? 'Document');
        }

        // Fire AllDocumentsValidated when status changes to valide and all collab docs are now valide
        if (isset($validated['status']) && $validated['status'] === 'valide' && $document->collaborateur_id) {
            $hasNonValidated = Document::where('collaborateur_id', $document->collaborateur_id)
                ->where('status', '!=', 'valide')
                ->exists();
            if (!$hasNonValidated) {
                AllDocumentsValidated::dispatch($document->collaborateur_id);
            }
        }

        return response()->json($document);
    }

    /**
     * Validate a document (mark as "valide").
     */
    public function validateDocument(Request $request, Document $document): JsonResponse
    {
        $document->update([
            'status' => 'valide',
            'validated_by' => auth()->id(),
            'validated_at' => now(),
        ]);

        // Fire DocumentValidated event
        if ($document->collaborateur_id) {
            DocumentValidated::dispatch($document->collaborateur_id, $document->nom ?? 'Document');
        }

        // Check if all docs for this collaborateur are validated
        $collabId = $document->collaborateur_id;
        if ($collabId) {
            $pending = Document::where('collaborateur_id', $collabId)
                ->where('status', '!=', 'valide')
                ->count();
            if ($pending === 0) {
                AllDocumentsValidated::dispatch($collabId);
                // Auto-award badge for all docs complete
                $collabUser = \App\Models\Collaborateur::find($collabId);
                if ($collabUser && $collabUser->user_id) {
                    \App\Services\BadgeAutoAwardService::checkAndAward($collabUser->user_id, 'docs_complete', $collabId);
                }
            }
        }

        return response()->json($document->fresh(['categorie', 'collaborateur', 'validatedBy']));
    }

    /**
     * Refuse a document with optional reason.
     */
    public function refuse(Request $request, Document $document): JsonResponse
    {
        $request->validate(['motif' => 'nullable|string']);

        $document->update([
            'status' => 'refuse',
            'refuse_motif' => $request->motif,
            'validated_by' => auth()->id(),
            'validated_at' => now(),
        ]);

        if ($document->collaborateur_id) {
            DocumentRefused::dispatch($document->collaborateur_id, $document->nom);
        }

        return response()->json($document->fresh(['categorie', 'collaborateur', 'validatedBy']));
    }

    public function destroy(Document $document): JsonResponse
    {
        // Delete the physical file if it exists
        if ($document->fichier_path && Storage::disk('local')->exists($document->fichier_path)) {
            Storage::disk('local')->delete($document->fichier_path);
        }

        $document->delete();
        return response()->json(null, 204);
    }

    public function categories(): JsonResponse
    {
        return response()->json(DocumentCategorie::with('documents')->get());
    }

    /**
     * Get documents summary per collaborateur (for the GED suivi tab).
     */
    public function summary(): JsonResponse
    {
        $summary = Document::select('collaborateur_id', 'status')
            ->selectRaw('COUNT(*) as count')
            ->whereNotNull('collaborateur_id')
            ->groupBy('collaborateur_id', 'status')
            ->get()
            ->groupBy('collaborateur_id');

        return response()->json($summary);
    }
}
