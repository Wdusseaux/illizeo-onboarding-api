<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\FieldConfig;
use App\Models\Integration;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DossierController extends Controller
{
    /**
     * Check completeness of a collaborateur's dossier.
     * Returns missing items and overall status.
     */
    public function check(Collaborateur $collaborateur): JsonResponse
    {
        $missing = [];
        $warnings = [];

        // 1. Check required base fields
        $requiredBase = ['prenom', 'nom', 'email', 'poste', 'site', 'departement', 'date_debut'];
        foreach ($requiredBase as $field) {
            if (empty($collaborateur->$field)) {
                $missing[] = ['type' => 'field', 'key' => $field, 'label' => ucfirst(str_replace('_', ' ', $field))];
            }
        }

        // 2. Check required custom fields
        $requiredFields = FieldConfig::where('actif', true)->where('obligatoire', true)->get();
        $customFields = $collaborateur->custom_fields ?? [];
        foreach ($requiredFields as $fc) {
            $isCustom = str_starts_with($fc->field_key, 'custom_');
            $val = $isCustom ? ($customFields[$fc->field_key] ?? null) : ($collaborateur->{$fc->field_key} ?? null);
            if (empty($val)) {
                $missing[] = ['type' => 'field', 'key' => $fc->field_key, 'label' => $fc->label];
            }
        }

        // 3. Check required documents (all uploaded docs must be validated)
        $docs = Document::where('collaborateur_id', $collaborateur->id)->get();
        $pendingDocs = $docs->filter(fn ($d) => $d->status !== 'valide');
        foreach ($pendingDocs as $doc) {
            $missing[] = ['type' => 'document', 'key' => "doc_{$doc->id}", 'label' => $doc->nom ?? $doc->fichier_nom ?? "Document #{$doc->id}", 'status' => $doc->status];
        }

        // 4. Check required signature documents
        $pendingSigs = DocumentAcknowledgement::where('collaborateur_id', $collaborateur->id)
            ->where('statut', 'en_attente')
            ->with('document')
            ->get();
        foreach ($pendingSigs as $ack) {
            if ($ack->document && $ack->document->obligatoire) {
                $missing[] = ['type' => 'signature', 'key' => "sig_{$ack->id}", 'label' => $ack->document->titre];
            }
        }

        // Determine status
        $isComplete = count($missing) === 0;
        $totalChecks = count($requiredBase) + $requiredFields->count() + $docs->count() + $pendingSigs->count();
        $completedChecks = $totalChecks - count($missing);
        $pct = $totalChecks > 0 ? round(($completedChecks / $totalChecks) * 100) : 100;

        // Auto-update dossier_status
        if ($isComplete && $collaborateur->dossier_status === 'incomplet') {
            $collaborateur->update(['dossier_status' => 'complet']);
        } elseif (!$isComplete && in_array($collaborateur->dossier_status, ['complet'])) {
            $collaborateur->update(['dossier_status' => 'incomplet']);
        }

        return response()->json([
            'collaborateur_id' => $collaborateur->id,
            'dossier_status' => $collaborateur->fresh()->dossier_status,
            'is_complete' => $isComplete,
            'completion_pct' => $pct,
            'total_checks' => $totalChecks,
            'completed_checks' => $completedChecks,
            'missing' => $missing,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Validate the dossier (RH confirms it's ready for SIRH export).
     */
    public function validate(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        // Re-check completeness
        $checkResponse = $this->check($collaborateur);
        $checkData = json_decode($checkResponse->getContent(), true);

        if (!$checkData['is_complete']) {
            return response()->json([
                'message' => 'Le dossier est incomplet. Veuillez compléter tous les éléments requis avant validation.',
                'missing' => $checkData['missing'],
            ], 422);
        }

        $collaborateur->update([
            'dossier_status' => 'valide',
            'dossier_validated_at' => now(),
            'dossier_validated_by' => auth()->id(),
        ]);

        // Notify relevant people
        NotificationService::send(auth()->id(), 'dossier',
            'Dossier validé',
            "Le dossier de {$collaborateur->prenom} {$collaborateur->nom} a été validé et est prêt pour export vers le SIRH.",
            'check-circle', '#4CAF50');

        return response()->json([
            'message' => "Dossier de {$collaborateur->prenom} {$collaborateur->nom} validé avec succès.",
            'collaborateur' => $collaborateur->fresh(),
        ]);
    }

    /**
     * Export the dossier to the configured SIRH.
     */
    public function export(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        if (!in_array($collaborateur->dossier_status, ['valide', 'exporte'])) {
            return response()->json(['message' => 'Le dossier doit être validé avant export.'], 422);
        }

        $target = $request->input('target', 'manual');

        // Build export payload
        $payload = [
            'prenom' => $collaborateur->prenom,
            'nom' => $collaborateur->nom,
            'email' => $collaborateur->email,
            'poste' => $collaborateur->poste,
            'site' => $collaborateur->site,
            'departement' => $collaborateur->departement,
            'date_debut' => $collaborateur->date_debut?->format('Y-m-d'),
            'civilite' => $collaborateur->civilite,
            'date_naissance' => $collaborateur->date_naissance,
            'nationalite' => $collaborateur->nationalite,
            'numero_avs' => $collaborateur->numero_avs,
            'telephone' => $collaborateur->telephone,
            'adresse' => $collaborateur->adresse,
            'ville' => $collaborateur->ville,
            'code_postal' => $collaborateur->code_postal,
            'pays' => $collaborateur->pays,
            'iban' => $collaborateur->iban,
            'type_contrat' => $collaborateur->type_contrat,
            'salaire_brut' => $collaborateur->salaire_brut,
            'devise' => $collaborateur->devise,
            'taux_activite' => $collaborateur->taux_activite,
            'periode_essai' => $collaborateur->periode_essai,
            'matricule' => $collaborateur->matricule,
            'manager_nom' => $collaborateur->manager_nom,
            'custom_fields' => $collaborateur->custom_fields,
        ];

        $exportResult = null;

        // Try to send to configured SIRH integration
        if ($target !== 'manual') {
            $integration = Integration::where('provider', $target)->where('actif', true)->first();
            if ($integration) {
                try {
                    switch ($target) {
                        case 'successfactors':
                            $exportResult = \App\Services\SuccessFactorsService::fromIntegration($integration)->createEmployee($payload);
                            break;
                        case 'personio':
                            $exportResult = \App\Services\PersonioService::fromIntegration($integration)->createEmployee($payload);
                            break;
                        case 'bamboohr':
                            $exportResult = \App\Services\BambooHRService::fromIntegration($integration)->createEmployee($payload);
                            break;
                        case 'lucca':
                            $exportResult = \App\Services\LuccaService::fromIntegration($integration)->createEmployee($payload);
                            break;
                    }
                } catch (\Exception $e) {
                    \Log::warning("SIRH export to {$target} failed for collaborateur {$collaborateur->id}: " . $e->getMessage());
                    return response()->json([
                        'message' => "Erreur d'export vers {$target} : " . $e->getMessage(),
                        'payload' => $payload,
                    ], 422);
                }
            }
        }

        $collaborateur->update([
            'dossier_status' => 'exporte',
            'dossier_exported_at' => now(),
            'dossier_export_target' => $target,
        ]);

        return response()->json([
            'message' => $target === 'manual'
                ? "Dossier marqué comme exporté. Les données sont disponibles ci-dessous pour import manuel."
                : "Dossier exporté vers {$target} avec succès.",
            'collaborateur' => $collaborateur->fresh(),
            'payload' => $payload,
            'export_result' => $exportResult,
        ]);
    }

    /**
     * Reset dossier status (e.g., after modifications).
     */
    public function reset(Collaborateur $collaborateur): JsonResponse
    {
        $collaborateur->update([
            'dossier_status' => 'incomplet',
            'dossier_validated_at' => null,
            'dossier_validated_by' => null,
            'dossier_exported_at' => null,
            'dossier_export_target' => null,
        ]);

        return response()->json(['message' => 'Statut du dossier réinitialisé.', 'collaborateur' => $collaborateur->fresh()]);
    }
}
