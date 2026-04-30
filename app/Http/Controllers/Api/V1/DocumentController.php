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
    /**
     * Country-specific document packs. Each entry maps a country code to a
     * list of documents to seed in the GED. Categories are matched by name —
     * created on-the-fly if they don't exist on the tenant. Run by the
     * importCountryPack endpoint when the admin clicks "Importer pack pays".
     *
     * Sources: requirements collected from local labour laws / standard hiring
     * checklists per country (see docs/country-packs.md if you need rationale).
     */
    private const COUNTRY_PACKS = [
        // Slug + titre matchent la convention existante du DefaultDataSeeder
        // (slug = code pays lowercase, titre = "Documents administratifs – Pays").
        // Tous les docs d'un pack vont dans la même catégorie pour qu'on les
        // retrouve facilement dans l'UI groupé par catégorie.
        'CH' => [
            'label' => 'Suisse',
            'slug'  => 'suisse',
            'titre' => 'Documents administratifs – Suisse',
            'docs' => [
                ['nom' => "Pièce d'identité (CNI / Passeport)",            'obligatoire' => true,  'desc' => 'Recto-verso, en cours de validité.'],
                ['nom' => 'Attestation sécurité sociale (AVS)',            'obligatoire' => true,  'desc' => 'Carte AVS — numéro à 13 chiffres.'],
                ['nom' => 'IBAN CH (RIB)',                                 'obligatoire' => true,  'desc' => 'Pour le versement du salaire.'],
                ['nom' => "Photo d'identité",                              'obligatoire' => true,  'desc' => 'Format passeport, fond clair.'],
                ['nom' => 'Permis de travail / Titre de séjour',           'obligatoire' => false, 'desc' => 'Pour les frontaliers (G), résidents (B/C) et étrangers hors UE.'],
                ['nom' => 'Casier judiciaire',                             'obligatoire' => false, 'desc' => "Selon le poste (postes sensibles, encadrement)."],
                ['nom' => 'Diplômes et certifications',                    'obligatoire' => false, 'desc' => 'Copie des diplômes mentionnés au CV.'],
                ['nom' => 'Justificatif de domicile',                      'obligatoire' => false, 'desc' => 'Bail, facture (électricité, internet…) récente.'],
            ],
        ],
        'FR' => [
            'label' => 'France',
            'slug'  => 'france',
            'titre' => 'Documents administratifs – France',
            'docs' => [
                ['nom' => 'Carte Vitale',                                  'obligatoire' => true,  'desc' => "Photocopie recto-verso de la carte d'assurance maladie."],
                ['nom' => 'RIB / IBAN français',                           'obligatoire' => true,  'desc' => "Relevé d'identité bancaire pour le versement du salaire."],
                ['nom' => 'Justificatif de domicile (moins de 3 mois)',    'obligatoire' => true,  'desc' => 'Quittance de loyer, facture EDF/eau/internet récente, attestation hébergeur.'],
                ['nom' => "Pièce d'identité (CNI / Passeport)",            'obligatoire' => true,  'desc' => 'Recto-verso, en cours de validité.'],
                ['nom' => 'Casier judiciaire (Bulletin n°3)',              'obligatoire' => false, 'desc' => "Selon le poste (postes sensibles, encadrement, etc.)."],
                ['nom' => 'Diplômes et certifications',                    'obligatoire' => false, 'desc' => 'Copie des diplômes mentionnés au CV.'],
                ['nom' => 'Titre de séjour / Permis de travail',           'obligatoire' => false, 'desc' => 'Pour les ressortissants hors UE.'],
                ['nom' => "Photo d'identité",                              'obligatoire' => false, 'desc' => "Format passeport, fond clair."],
            ],
        ],
        'BE' => [
            'label' => 'Belgique',
            'slug'  => 'belgique',
            'titre' => 'Documents administratifs – Belgique',
            'docs' => [
                ['nom' => 'Carte d\'identité belge (eID)',                 'obligatoire' => true,  'desc' => 'Recto-verso, en cours de validité.'],
                ['nom' => 'Numéro de registre national',                   'obligatoire' => true,  'desc' => 'Numéro à 11 chiffres figurant sur l\'eID.'],
                ['nom' => 'IBAN BE (RIB)',                                 'obligatoire' => true,  'desc' => 'Pour le versement du salaire.'],
                ['nom' => 'Attestation mutuelle',                          'obligatoire' => true,  'desc' => 'Affiliation à une mutualité belge.'],
                ['nom' => 'DIMONA — déclaration immédiate emploi',         'obligatoire' => true,  'desc' => "Confirmation de la déclaration DIMONA par l'employeur."],
                ['nom' => 'Permis de travail / titre de séjour',           'obligatoire' => false, 'desc' => 'Pour les non-ressortissants UE.'],
                ['nom' => 'Diplômes et certifications',                    'obligatoire' => false, 'desc' => null],
            ],
        ],
        'LU' => [
            'label' => 'Luxembourg',
            'slug'  => 'luxembourg',
            'titre' => 'Documents administratifs – Luxembourg',
            'docs' => [
                ['nom' => 'Pièce d\'identité (CNI / Passeport)',           'obligatoire' => true,  'desc' => null],
                ['nom' => 'N° matricule sécurité sociale (CCSS)',          'obligatoire' => true,  'desc' => 'Matricule à 13 chiffres délivré par le Centre commun de la sécurité sociale.'],
                ['nom' => 'IBAN LU',                                       'obligatoire' => true,  'desc' => 'Pour le versement du salaire.'],
                ['nom' => 'Carte de séjour / Permis de travail',           'obligatoire' => false, 'desc' => 'Pour les frontaliers et étrangers hors UE.'],
                ['nom' => 'Casier judiciaire (Bulletin n°3 ou n°5)',       'obligatoire' => false, 'desc' => 'Selon le poste.'],
                ['nom' => 'Diplômes et certifications',                    'obligatoire' => false, 'desc' => null],
            ],
        ],
        'DE' => [
            'label' => 'Allemagne',
            'slug'  => 'allemagne',
            'titre' => 'Documents administratifs – Allemagne',
            'docs' => [
                ['nom' => 'Personalausweis / Reisepass',                   'obligatoire' => true,  'desc' => "Carte d'identité allemande ou passeport."],
                ['nom' => 'Sozialversicherungsausweis',                    'obligatoire' => true,  'desc' => "Carte d'assurance sociale."],
                ['nom' => 'Steuer-Identifikationsnummer (Steuer-ID)',      'obligatoire' => true,  'desc' => 'Numéro fiscal à 11 chiffres.'],
                ['nom' => 'Krankenversicherung — preuve d\'affiliation',   'obligatoire' => true,  'desc' => "Attestation de l'organisme d'assurance maladie."],
                ['nom' => 'IBAN DE',                                       'obligatoire' => true,  'desc' => 'Pour le versement du salaire.'],
                ['nom' => 'Aufenthaltstitel / Arbeitserlaubnis',           'obligatoire' => false, 'desc' => 'Titre de séjour / permis de travail (étrangers hors UE).'],
                ['nom' => 'Führungszeugnis',                               'obligatoire' => false, 'desc' => "Casier judiciaire — selon le poste."],
                ['nom' => 'Zeugnisse / Diplomes',                          'obligatoire' => false, 'desc' => 'Diplômes et certificats.'],
            ],
        ],
    ];

    /**
     * POST /document-templates/import-country-pack
     * Body: { country: 'FR' | 'BE' | 'LU' | 'DE' }
     *
     * Crée tous les documents-templates manquants pour le pays demandé.
     * Idempotent : un template avec le même nom dans la même catégorie n'est
     * pas dupliqué — on retourne combien ont été créés vs déjà présents.
     */
    public function importCountryPack(Request $request): JsonResponse
    {
        $request->validate([
            'country' => 'required|string|in:' . implode(',', array_keys(self::COUNTRY_PACKS)),
        ]);
        $code = strtoupper($request->country);
        $pack = self::COUNTRY_PACKS[$code];

        // 1 catégorie par pack — slug + titre matchent la convention existante.
        $category = DocumentCategorie::firstOrCreate(
            ['slug' => $pack['slug']],
            ['titre' => $pack['titre']]
        );

        $created = 0;
        $skipped = 0;
        $createdDocs = [];

        foreach ($pack['docs'] as $doc) {
            // Skip si un template avec ce nom existe déjà dans cette catégorie.
            $exists = Document::where('is_template', true)
                ->where('nom', $doc['nom'])
                ->where('categorie_id', $category->id)
                ->exists();
            if ($exists) { $skipped++; continue; }

            $created++;
            $createdDocs[] = Document::create([
                'nom' => $doc['nom'],
                'description' => $doc['desc'] ?? null,
                'obligatoire' => $doc['obligatoire'] ?? false,
                'type' => 'upload',
                'is_template' => true,
                'categorie_id' => $category->id,
            ])->load('categorie');
        }

        return response()->json([
            'country' => $code,
            'label' => $pack['label'],
            'category_id' => $category->id,
            'created' => $created,
            'skipped' => $skipped,
            'documents' => $createdDocs,
        ]);
    }

    /**
     * GET /document-templates/country-packs — liste les packs disponibles
     * pour affichage dans le UI admin (label + nb de docs).
     */
    public function countryPacks(): JsonResponse
    {
        $packs = [];
        foreach (self::COUNTRY_PACKS as $code => $pack) {
            $packs[] = [
                'code' => $code,
                'label' => $pack['label'],
                'docs_count' => count($pack['docs']),
            ];
        }
        return response()->json($packs);
    }

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

        // Authorization: an employee can upload for their own collaborateur record;
        // anyone else needs documents:edit permission (admin / RH).
        $user = $request->user();
        $myCollab = \App\Models\Collaborateur::where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();
        $isSelfUpload = $myCollab && (int) $myCollab->id === (int) $collabId;
        if (!$isSelfUpload && !$user->hasModulePermission('documents', 'edit')) {
            abort(403, 'Insufficient permission to upload for this collaborateur.');
        }

        // Resolve a categorie_id (NOT NULL column). Order:
        //   1. explicit categorie_id from the payload
        //   2. lookup by title string (request->categorie)
        //   3. find-or-create a generic "Onboarding" bucket so self-uploads always succeed
        $categorieId = $request->categorie_id;
        if (!$categorieId && $request->categorie) {
            $cat = DocumentCategorie::where('titre', $request->categorie)->first();
            if (!$cat) {
                $cat = DocumentCategorie::create([
                    'slug' => \Illuminate\Support\Str::slug($request->categorie) ?: 'onboarding',
                    'titre' => $request->categorie,
                ]);
            }
            $categorieId = $cat->id;
        }
        if (!$categorieId) {
            $cat = DocumentCategorie::firstOrCreate(
                ['slug' => 'onboarding'],
                ['titre' => 'Onboarding']
            );
            $categorieId = $cat->id;
        }

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
                'categorie_id' => $categorieId,
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
