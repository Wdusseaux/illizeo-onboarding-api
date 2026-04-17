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

    // ── Build variable map from collaborateur data ─────────
    private function buildVariableMap(Collaborateur $collab): array
    {
        $companySettings = \App\Models\CompanySetting::pluck('value', 'key');
        $manager = $collab->manager;
        $hireDate = $collab->date_debut ? \Carbon\Carbon::parse($collab->date_debut)->format('d/m/Y') : '';
        $birthday = $collab->date_naissance ? \Carbon\Carbon::parse($collab->date_naissance)->format('d/m/Y') : '';
        $probEnd = $collab->date_fin_essai ? \Carbon\Carbon::parse($collab->date_fin_essai)->format('d/m/Y') : '';
        $contractEnd = $collab->date_fin_contrat ? \Carbon\Carbon::parse($collab->date_fin_contrat)->format('d/m/Y') : '';

        return [
            'first_name' => $collab->prenom ?? '',
            'last_name' => $collab->nom ?? '',
            'full_name' => trim(($collab->prenom ?? '') . ' ' . ($collab->nom ?? '')),
            'position' => $collab->poste ?? '',
            'department_name' => $collab->departement ?? '',
            'hire_date' => $hireDate,
            'email' => $collab->email ?? '',
            'site' => $collab->site ?? '',
            'birthday' => $birthday,
            'nationality' => $collab->nationalite ?? '',
            'address' => $collab->adresse ?? '',
            'city' => $collab->ville ?? '',
            'postal_code' => $collab->code_postal ?? '',
            'country' => $collab->pays ?? '',
            'phone' => $collab->telephone ?? '',
            'avs_number' => $collab->numero_avs ?? '',
            'iban' => $collab->iban ?? '',
            'contract_type' => $collab->type_contrat ?? '',
            'company_name' => $companySettings['company_name'] ?? tenant('nom') ?? 'Illizeo',
            'company_address' => $companySettings['company_address'] ?? '',
            'office_name' => $collab->site ?? '',
            'supervisor_first_name' => $manager?->prenom ?? '',
            'supervisor_last_name' => $manager?->nom ?? '',
            'supervisor_full_name' => trim(($manager?->prenom ?? '') . ' ' . ($manager?->nom ?? '')),
            'supervisor_position' => $manager?->poste ?? 'Manager',
            'document_date' => now()->format('d/m/Y'),
            'fix_salary' => $collab->salaire_brut ?? '',
            'currency' => $collab->devise ?? 'CHF',
            'fte' => $collab->fte ?? $collab->taux_activite ?? '100%',
            'weekly_working_hours' => $collab->work_schedule ?? '40',
            'contract_end_date' => $contractEnd,
            'probation_end_date' => $probEnd,
            'matricule' => $collab->matricule ?? '',
        ];
    }

    // ── Resolve collaborateur from request ──────────────
    private function resolveCollab(Request $request): ?Collaborateur
    {
        $collabId = $request->query('collaborateur_id');
        if ($collabId) return Collaborateur::find($collabId);
        return Collaborateur::where('user_id', auth()->id())->first();
    }

    // ── Get variable map for a contrat + collaborateur ───
    public function generateForCollaborateur(Request $request, Contrat $contrat): JsonResponse
    {
        $collab = $this->resolveCollab($request);
        if (!$collab) return response()->json(['error' => 'Collaborateur not found'], 404);

        if (!$contrat->fichier_path || !Storage::disk('local')->exists($contrat->fichier_path)) {
            return response()->json(['error' => 'No template file'], 404);
        }

        $variables = $this->buildVariableMap($collab);

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
            'download_url' => "/api/v1/contrats/{$contrat->id}/download?collaborateur_id={$collab->id}",
        ]);
    }

    // ── Merge DOCX template with variables and return file ───
    public function downloadMerged(Request $request, Contrat $contrat)
    {
        $collab = $this->resolveCollab($request);
        if (!$collab) return response()->json(['error' => 'Collaborateur not found'], 404);

        if (!$contrat->fichier_path || !Storage::disk('local')->exists($contrat->fichier_path)) {
            return response()->json(['error' => 'No template file'], 404);
        }

        $variables = $this->buildVariableMap($collab);
        $templatePath = Storage::disk('local')->path($contrat->fichier_path);
        $ext = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));
        $format = $request->query('format', 'docx'); // docx or pdf

        if ($ext === 'pdf') {
            // PDF templates: just return as-is (can't merge variables into PDF easily)
            return response()->download($templatePath, $this->sanitizeFilename($contrat, $collab, 'pdf'));
        }

        // DOCX merge using PhpWord TemplateProcessor
        try {
            $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

            // Replace all ${variable} placeholders
            foreach ($variables as $key => $value) {
                $templateProcessor->setValue('${' . $key . '}', $value);
                // Also support {variable} without $ and {{variable}}
                $templateProcessor->setValue($key, $value);
            }

            // Save merged DOCX to temp file
            $tempDir = storage_path('app/private/temp');
            if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);
            $mergedPath = $tempDir . '/' . uniqid('contrat_') . '.docx';
            $templateProcessor->saveAs($mergedPath);

            if ($format === 'pdf') {
                // Convert DOCX to PDF via PhpWord + Dompdf
                $pdfPath = $this->convertDocxToPdf($mergedPath);
                if ($pdfPath) {
                    $filename = $this->sanitizeFilename($contrat, $collab, 'pdf');
                    return response()->download($pdfPath, $filename)->deleteFileAfterSend(true);
                }
                // Fallback: return DOCX if PDF conversion fails
            }

            $filename = $this->sanitizeFilename($contrat, $collab, 'docx');
            return response()->download($mergedPath, $filename)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Merge failed: ' . $e->getMessage()], 500);
        }
    }

    // ── Convert DOCX to PDF via PhpWord + Dompdf ─────────
    private function convertDocxToPdf(string $docxPath): ?string
    {
        try {
            // Set Dompdf as the PDF renderer
            \PhpOffice\PhpWord\Settings::setPdfRendererName(\PhpOffice\PhpWord\Settings::PDF_RENDERER_DOMPDF);
            \PhpOffice\PhpWord\Settings::setPdfRendererPath(base_path('vendor/dompdf/dompdf'));

            $phpWord = \PhpOffice\PhpWord\IOFactory::load($docxPath);
            $pdfPath = str_replace('.docx', '.pdf', $docxPath);
            $pdfWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'PDF');
            $pdfWriter->save($pdfPath);

            // Clean up DOCX temp
            @unlink($docxPath);
            return $pdfPath;
        } catch (\Exception $e) {
            \Log::warning('DOCX to PDF conversion failed: ' . $e->getMessage());
            return null;
        }
    }

    // ── Build clean filename ──────────────────────────────
    private function sanitizeFilename(Contrat $contrat, Collaborateur $collab, string $ext): string
    {
        $name = str_replace(' ', '_', $contrat->nom);
        $collabName = str_replace(' ', '_', $collab->prenom . '_' . $collab->nom);
        return "{$name}_{$collabName}.{$ext}";
    }

    // ── Count ${...} variables in a file ──────────────────
    private function countVariables($file): int
    {
        $content = file_get_contents($file->getRealPath());
        preg_match_all('/\$\{(\w+)\}/', $content, $matches);
        return count(array_unique($matches[1]));
    }
}
