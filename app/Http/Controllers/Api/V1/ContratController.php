<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use App\Models\Collaborateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContratController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Contrat::all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'type' => 'required|string',
            'juridiction' => 'required|string',
            'variables' => 'nullable|integer',
            'actif' => 'nullable|boolean',
            'fichier' => 'nullable|string',
            'translations' => 'nullable|array',
        ]);

        $contrat = Contrat::create($validated);
        return response()->json($contrat, 201);
    }

    public function show(Contrat $contrat): JsonResponse
    {
        return response()->json($contrat);
    }

    public function update(Request $request, Contrat $contrat): JsonResponse
    {
        $contrat->update($request->validate([
            'nom' => 'sometimes|string|max:255',
            'type' => 'sometimes|string',
            'juridiction' => 'sometimes|string',
            'variables' => 'nullable|integer',
            'actif' => 'nullable|boolean',
            'fichier' => 'nullable|string',
            'translations' => 'nullable|array',
        ]));

        return response()->json($contrat);
    }

    public function destroy(Contrat $contrat): JsonResponse
    {
        $contrat->delete();
        return response()->json(null, 204);
    }

    // ── File upload for contrat template ──────────────────
    public function uploadFile(Request $request, Contrat $contrat): JsonResponse
    {
        $request->validate([
            'fichier' => 'required|file|max:10240|mimes:pdf,doc,docx,odt',
        ]);

        $file = $request->file('fichier');
        $filename = $file->getClientOriginalName();
        $path = $file->storeAs('contrat-templates', $contrat->id . '_' . $filename, 'local');

        $contrat->update([
            'fichier' => $filename,
            'fichier_path' => $path,
            'variables' => $this->countVariables($file),
        ]);

        return response()->json([
            'filename' => $filename,
            'path' => $path,
            'variables' => $contrat->variables,
        ]);
    }

    // ── Download the original template ────────────────────
    public function downloadTemplate(Contrat $contrat)
    {
        if (!$contrat->fichier_path || !Storage::disk('local')->exists($contrat->fichier_path)) {
            return response()->json(['error' => 'No template file'], 404);
        }

        return Storage::disk('local')->download($contrat->fichier_path, $contrat->fichier);
    }

    // ── Generate personalized PDF for a collaborateur ─────
    public function generateForCollaborateur(Request $request, Contrat $contrat): JsonResponse
    {
        $collabId = $request->query('collaborateur_id');
        if (!$collabId) {
            // Use current user's collaborateur
            $collab = Collaborateur::where('user_id', auth()->id())->first();
        } else {
            $collab = Collaborateur::find($collabId);
        }

        if (!$collab) {
            return response()->json(['error' => 'Collaborateur not found'], 404);
        }

        if (!$contrat->fichier_path || !Storage::disk('local')->exists($contrat->fichier_path)) {
            return response()->json(['error' => 'No template file'], 404);
        }

        // Read the template and replace variables
        $content = Storage::disk('local')->get($contrat->fichier_path);

        // Build variable map from collaborateur + company data
        $companySettings = \App\Models\CompanySetting::pluck('value', 'key');
        $manager = $collab->manager;

        $variables = [
            'first_name' => $collab->prenom,
            'last_name' => $collab->nom,
            'position' => $collab->poste,
            'department_name' => $collab->departement,
            'hire_date' => $collab->date_debut,
            'email' => $collab->email ?? '',
            'site' => $collab->site ?? '',
            'birthday' => $collab->date_naissance ?? '',
            'company_name' => $companySettings['company_name'] ?? 'Illizeo',
            'company_address' => $companySettings['company_address'] ?? '',
            'office_name' => $collab->site ?? '',
            'supervisor_first_name' => $manager?->prenom ?? '',
            'supervisor_last_name' => $manager?->nom ?? '',
            'supervisor_position' => $manager?->poste ?? 'Manager',
            'document_date' => now()->format('d/m/Y'),
            'fix_salary' => $collab->salaire ?? '',
            'fte' => $collab->fte ?? '100%',
            'weekly_working_hours' => $collab->heures_semaine ?? '40',
            'contract_end_date' => $collab->date_fin ?? '',
            'termination_date' => $collab->date_fin ?? '',
            'last_working_day' => $collab->date_fin ?? '',
            'probation_end_date' => $collab->date_fin_essai ?? '',
        ];

        // Return the variable map + contrat info (frontend will display it)
        return response()->json([
            'contrat' => $contrat,
            'collaborateur' => [
                'id' => $collab->id,
                'prenom' => $collab->prenom,
                'nom' => $collab->nom,
                'poste' => $collab->poste,
            ],
            'variables' => $variables,
            'template_url' => "/api/v1/contrats/{$contrat->id}/template",
        ]);
    }

    // ── Count ${...} variables in a file ──────────────────
    private function countVariables($file): int
    {
        $content = file_get_contents($file->getRealPath());
        preg_match_all('/\$\{(\w+)\}/', $content, $matches);
        return count(array_unique($matches[1]));
    }
}
