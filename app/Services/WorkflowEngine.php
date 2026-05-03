<?php

namespace App\Services;

use App\Events\ActionCompleted;
use App\Events\AllDocumentsValidated;
use App\Events\AnniversaireEmbauche;
use App\Events\AnniversairePersonnel;
use App\Events\ArriveeJour;
use App\Events\CollaborateurEnRetard;
use App\Events\ContratReady;
use App\Events\ContratSigned;
use App\Events\CooptationValidated;
use App\Events\DeadlineApproaching;
use App\Events\DocumentRefused;
use App\Events\DocumentSubmitted;
use App\Events\DocumentValidated;
use App\Events\FinEssaiApproche;
use App\Events\FormulaireSubmitted;
use App\Events\MessageReceived;
use App\Events\NewCollaborateur;
use App\Events\NpsSoumis;
use App\Events\ParcoursCompleted;
use App\Events\ParcoursCreated;
use App\Events\ParcoursOffboardingTermine;
use App\Events\PeriodeEssaiTerminee;
use App\Events\PostArrivalMilestone;
use App\Events\PreArrivalReminder;
use App\Events\RenouvellementCDD;
use App\Events\SignatureReminder;
use App\Events\WeeklyDigest;
use App\Models\Action;
use App\Models\Badge;
use App\Models\CollaborateurAction;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use App\Models\Collaborateur;
use App\Models\Cooptation;
use App\Models\EmailTemplate;
use App\Models\Groupe;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WorkflowEngine
{
    /**
     * Map event class names to declencheur strings used in the DB.
     */
    private static array $triggerMap = [
        // Document lifecycle
        DocumentSubmitted::class => 'Document soumis',
        DocumentValidated::class => 'Document validé',
        DocumentRefused::class => 'Document refusé',
        AllDocumentsValidated::class => 'Tous documents validés',
        // Parcours lifecycle
        ParcoursCreated::class => 'Parcours créé',
        ParcoursCompleted::class => 'Parcours complété à 100%',
        ParcoursOffboardingTermine::class => 'Fin de parcours offboarding',
        // Actions & forms
        ActionCompleted::class => 'Action complétée',
        FormulaireSubmitted::class => 'Formulaire soumis',
        // People
        NewCollaborateur::class => 'Nouveau collaborateur',
        // Time-based (fired by CheckDeadlines command)
        DeadlineApproaching::class => 'J-7 avant date limite',
        PreArrivalReminder::class => 'J-3 avant date d\'arrivée',
        ArriveeJour::class => 'Jour d\'arrivée (J+0)',
        PostArrivalMilestone::class => 'Milestone post-arrivée',
        PeriodeEssaiTerminee::class => 'Période d\'essai terminée',
        FinEssaiApproche::class => 'Fin de période d\'essai (J-15)',
        RenouvellementCDD::class => 'Renouvellement CDD (J-60)',
        AnniversaireEmbauche::class => 'Anniversaire d\'embauche',
        AnniversairePersonnel::class => 'Anniversaire personnel',
        CollaborateurEnRetard::class => 'Collaborateur en retard',
        WeeklyDigest::class => 'Hebdomadaire (lundi)',
        SignatureReminder::class => 'J+3 après envoi signature',
        // Contracts & signatures
        ContratReady::class => 'Contrat prêt',
        ContratSigned::class => 'Contrat signé',
        // Other
        CooptationValidated::class => 'Cooptation validée',
        NpsSoumis::class => 'Questionnaire NPS soumis',
        MessageReceived::class => 'Nouveau message reçu',
    ];

    /**
     * Process an event against all active workflows.
     */
    public static function handle(object $event): void
    {
        $triggerLabel = self::$triggerMap[get_class($event)] ?? null;
        if (!$triggerLabel) return;

        $workflows = Workflow::where('declencheur', $triggerLabel)
            ->where('actif', true)
            ->get();

        foreach ($workflows as $workflow) {
            // Multi-step workflow (new format)
            if (!empty($workflow->steps) && is_array($workflow->steps)) {
                self::executeSteps($workflow, $event);
            } else {
                // Legacy single-action workflow
                self::executeAction($workflow, $event);
            }
        }
    }

    /**
     * Execute a multi-step workflow sequentially.
     */
    private static function executeSteps(Workflow $workflow, object $event): void
    {
        self::executeStepsFrom($workflow, $event, 0);
    }

    /**
     * Execute steps starting from a given index. Used for delayed resumption.
     */
    public static function executeStepsFrom(Workflow $workflow, object $event, int $fromIndex): void
    {
        $steps = $workflow->steps;
        if (!is_array($steps)) return;

        for ($i = $fromIndex; $i < count($steps); $i++) {
            $step = $steps[$i];
            $type = $step['type'] ?? 'action';

            if ($type === 'condition') {
                if (!self::evaluateCondition($step, $event)) {
                    Log::info("Workflow {$workflow->nom}: condition not met at step {$i}, skipping remaining steps");
                    return;
                }
                continue;
            }

            if ($type === 'delay') {
                $delayValue = (int) ($step['delay_value'] ?? 1);
                $delayUnit = $step['delay_unit'] ?? 'days';
                $delayMinutes = match($delayUnit) {
                    'hours' => $delayValue * 60,
                    'days' => $delayValue * 1440,
                    'weeks' => $delayValue * 10080,
                    default => $delayValue * 1440,
                };

                // Schedule remaining steps after delay
                $eventData = self::serializeEvent($event);
                \App\Jobs\ExecuteWorkflowSteps::dispatch(
                    $workflow->id,
                    tenant()->id,
                    get_class($event),
                    $eventData,
                    $i + 1, // Resume from next step
                )->delay(now()->addMinutes($delayMinutes));

                Log::info("Workflow {$workflow->nom}: delay {$delayValue} {$delayUnit} — scheduled job for step " . ($i + 1));
                return; // Stop current execution, job will resume
            }

            // Action step
            $stepWorkflow = clone $workflow;
            $stepWorkflow->action = $step['action'] ?? $workflow->action;
            $stepWorkflow->destinataire = $step['destinataire'] ?? $workflow->destinataire;
            $stepWorkflow->email_subject = $step['email_subject'] ?? $workflow->email_subject;
            $stepWorkflow->email_body = $step['email_body'] ?? $workflow->email_body;
            $stepWorkflow->bot_message = $step['bot_message'] ?? $workflow->bot_message;
            $stepWorkflow->badge_name = $step['badge_name'] ?? $workflow->badge_name;
            $stepWorkflow->badge_icon = $step['badge_icon'] ?? $workflow->badge_icon;
            $stepWorkflow->badge_color = $step['badge_color'] ?? $workflow->badge_color;
            $stepWorkflow->target_user_id = $step['target_user_id'] ?? $workflow->target_user_id;
            $stepWorkflow->target_group_id = $step['target_group_id'] ?? $workflow->target_group_id;
            $stepWorkflow->target_action_id = $step['target_action_id'] ?? $workflow->target_action_id;

            self::executeAction($stepWorkflow, $event);
        }
    }

    /**
     * Serialize event data for job queue storage.
     */
    public static function serializeEvent(object $event): array
    {
        $data = [];
        foreach (['collaborateurId', 'actionTitle', 'documentName', 'parcoursName',
                   'formulaireName', 'collaborateurName', 'contratName', 'candidateName',
                   'cooptationId'] as $prop) {
            if (property_exists($event, $prop)) {
                $data[$prop] = $event->$prop;
            }
        }
        return $data;
    }

    /**
     * Reconstruct event from serialized data for delayed execution.
     */
    public static function reconstructEvent(string $eventClass, array $data): ?object
    {
        if (!class_exists($eventClass)) return null;

        $event = new \stdClass();
        foreach ($data as $key => $value) {
            $event->$key = $value;
        }
        return $event;
    }

    /**
     * Evaluate a condition step.
     */
    private static function evaluateCondition(array $step, object $event): bool
    {
        $field = $step['field'] ?? '';
        $operator = $step['operator'] ?? '==';
        $value = $step['value'] ?? '';

        // Get the field value from event or collaborateur
        $collaborateur = property_exists($event, 'collaborateurId')
            ? Collaborateur::find($event->collaborateurId) : null;

        $actual = match($field) {
            'site' => $collaborateur?->site ?? '',
            'departement' => $collaborateur?->departement ?? '',
            'poste' => $collaborateur?->poste ?? '',
            'type_contrat' => $collaborateur?->type_contrat ?? '',
            'pays' => $collaborateur?->pays ?? '',
            default => '',
        };

        return match($operator) {
            '==' => strtolower($actual) === strtolower($value),
            '!=' => strtolower($actual) !== strtolower($value),
            'contains' => str_contains(strtolower($actual), strtolower($value)),
            default => true,
        };
    }

    private static function executeAction(Workflow $workflow, object $event): void
    {
        // CooptationValidated has no collaborateurId — resolve from cooptation
        if ($event instanceof CooptationValidated) {
            $cooptation = Cooptation::find($event->cooptationId);
            $collaborateur = $cooptation?->collaborateur;
        } else {
            $collaborateur = Collaborateur::find($event->collaborateurId);
        }

        if (!$collaborateur) return;

        $user = $collaborateur->user;
        $recipientIds = self::resolveRecipients($workflow->destinataire, $collaborateur, $workflow);

        // Build a context label from available event properties
        $contextLabel = property_exists($event, 'actionTitle') ? $event->actionTitle : (
            property_exists($event, 'documentName') ? $event->documentName : (
                property_exists($event, 'parcoursName') ? $event->parcoursName : (
                    property_exists($event, 'formulaireName') ? $event->formulaireName : (
                        property_exists($event, 'collaborateurName') ? $event->collaborateurName : (
                            property_exists($event, 'contratName') ? $event->contratName : (
                                property_exists($event, 'candidateName') ? $event->candidateName : 'Rappel'
                            )
                        )
                    )
                )
            )
        );

        $collabFullName = "{$collaborateur->prenom} {$collaborateur->nom}";
        $triggerLabel = self::$triggerMap[get_class($event)] ?? null;
        $vars = self::buildVariables($collaborateur, $event);

        switch ($workflow->action) {
            case 'Envoyer email de relance':
                $subject = $workflow->email_subject ?: "Relance : {$contextLabel}";
                $body = $workflow->email_body ?: "Bonjour {{prenom}},\n\nCeci est un rappel concernant : {$contextLabel}.\n\nMerci de bien vouloir traiter cette action dans les meilleurs délais.\n\nCordialement,\nL'équipe Illizeo";
                foreach ($recipientIds as $uid) {
                    NotificationService::reminder($uid, $contextLabel, 'Workflow');
                    self::sendEmail($uid, $subject, $body, $triggerLabel, $vars);
                }
                break;

            case 'Envoyer pour validation au Manager':
                $managerId = DB::table('collaborateur_accompagnants')
                    ->where('collaborateur_id', $collaborateur->id)
                    ->where('role', 'manager')
                    ->value('user_id');
                if ($managerId) {
                    NotificationService::actionAssigned($managerId, "Validation requise : {$collabFullName}", 'Workflow');
                    self::sendEmail(
                        $managerId,
                        $workflow->email_subject ?: "Validation requise : {$collabFullName}",
                        $workflow->email_body ?: "Bonjour,\n\nUne validation est requise pour le dossier de {{prenom}} {{nom}}.\n\nMerci de vous connecter à Illizeo pour traiter cette demande.\n\nCordialement,\nL'équipe Illizeo",
                        $triggerLabel, $vars
                    );
                }
                break;

            case "Notifier l'équipe RH":
                foreach ($recipientIds as $uid) {
                    NotificationService::send($uid, 'workflow', "Workflow : {$workflow->nom}", $workflow->action, 'bell', '#1A73E8');
                    self::sendEmail($uid, "Notification : {$workflow->nom}", "Bonjour,\n\nLe workflow « {$workflow->nom} » s'est déclenché pour {{prenom}} {{nom}}.\n\nCordialement,\nIllizeo", $triggerLabel, $vars);
                }
                break;

            case 'Envoyer confirmation au collaborateur':
                if ($user) {
                    NotificationService::send($user->id, 'workflow', 'Confirmation', 'Votre dossier a été traité avec succès', 'check', '#4CAF50');
                    self::sendEmail(
                        $user->id,
                        $workflow->email_subject ?: 'Confirmation de traitement de votre dossier',
                        $workflow->email_body ?: "Bonjour {{prenom}},\n\nNous vous confirmons que votre dossier a été traité avec succès.\n\nCordialement,\nL'équipe Illizeo",
                        $triggerLabel, $vars
                    );
                }
                break;

            case 'Envoyer pour approbation Admin RH':
                $adminRhIds = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_rh'))->pluck('id');
                foreach ($adminRhIds as $uid) {
                    NotificationService::send($uid, 'workflow', 'Approbation requise', "{$collabFullName} : {$workflow->nom}", 'alert', '#F9A825');
                    self::sendEmail(
                        $uid,
                        $workflow->email_subject ?: "Approbation requise : {$collabFullName}",
                        $workflow->email_body ?: "Bonjour,\n\nUne approbation est requise pour {{prenom}} {{nom}} dans le cadre du workflow « {$workflow->nom} ».\n\nMerci de vous connecter à Illizeo.\n\nCordialement,\nL'équipe Illizeo",
                        $triggerLabel, $vars
                    );
                }
                break;

            case 'Assigner action automatiquement':
                if (!$workflow->target_action_id) {
                    Log::warning("Workflow '{$workflow->nom}': 'Assigner action' configured but target_action_id is null — no action created.");
                    break;
                }
                $action = Action::find($workflow->target_action_id);
                if (!$action) {
                    Log::warning("Workflow '{$workflow->nom}': target_action_id={$workflow->target_action_id} not found.");
                    break;
                }
                $assignment = CollaborateurAction::firstOrCreate(
                    ['collaborateur_id' => $collaborateur->id, 'action_id' => $action->id],
                    ['status' => 'a_faire']
                );
                if ($user && $assignment->wasRecentlyCreated) {
                    NotificationService::actionAssigned($user->id, $action->titre, 'Workflow');
                }
                Log::info("Workflow: assigned action #{$action->id} '{$action->titre}' to {$collabFullName} (created=" . ($assignment->wasRecentlyCreated ? 'yes' : 'already-existed') . ")");
                break;

            case 'Changer statut du parcours':
                $collaborateur->update(['status' => 'termine']);
                break;

            case 'Envoyer un message IllizeoBot':
                if ($user) {
                    $message = $workflow->bot_message ?: "Workflow automatique : {$workflow->nom}";
                    IllizeoBotService::sendTo($user->id, $message);
                }
                break;

            case 'Envoyer via Teams':
                $integration = Integration::where('provider', 'teams')->where('actif', true)->first();
                if (!$integration || empty($integration->config['webhook_url'] ?? null)) {
                    Log::warning("Workflow '{$workflow->nom}': Teams integration is not configured (provider=teams, actif=true, webhook_url required).");
                    // Surface the misconfiguration to RH admins so they can fix it.
                    foreach (User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin_rh', 'admin']))->pluck('id') as $adminId) {
                        NotificationService::send($adminId, 'workflow', "Workflow Teams non configuré", "Le workflow « {$workflow->nom} » n'a pas pu envoyer un message Teams : intégration absente ou inactive.", 'alert', '#E53935', ['workflow_id' => $workflow->id]);
                    }
                    break;
                }
                try {
                    $teamsService = TeamsService::fromIntegration($integration);
                    $teamsService->sendWebhookCard($workflow->nom, self::buildEventDescription($event));
                    Log::info("Workflow '{$workflow->nom}': Teams card sent.");
                } catch (\Exception $e) {
                    Log::warning("Teams workflow '{$workflow->nom}' failed: " . $e->getMessage());
                    foreach (User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin_rh', 'admin']))->pluck('id') as $adminId) {
                        NotificationService::send($adminId, 'workflow', "Workflow Teams en échec", "Le workflow « {$workflow->nom} » a échoué : " . $e->getMessage(), 'alert', '#E53935', ['workflow_id' => $workflow->id]);
                    }
                }
                break;

            case 'Envoyer pour signature':
                $sigIntegration = Integration::whereIn('provider', ['docusign', 'ugosign'])->where('actif', true)->first();
                if ($sigIntegration && $user) {
                    NotificationService::send($user->id, 'workflow', 'Signature requise', "Un document nécessite votre signature électronique via {$sigIntegration->provider}", 'alert', '#F9A825');
                    Log::info("Workflow: signature request for {$collabFullName} via {$sigIntegration->provider}");
                } elseif ($user) {
                    NotificationService::send($user->id, 'workflow', 'Signature requise', 'Un document nécessite votre signature', 'alert', '#F9A825');
                    Log::info("Workflow: signature request for {$collabFullName} — no active signature integration");
                }
                break;

            case 'Attribuer un badge':
                if ($user) {
                    Badge::create([
                        'user_id' => $user->id,
                        'collaborateur_id' => $collaborateur->id,
                        'nom' => $workflow->badge_name ?: $workflow->nom,
                        'description' => "Obtenu via le workflow : {$workflow->nom}",
                        'icon' => $workflow->badge_icon ?: 'trophy',
                        'color' => $workflow->badge_color ?: '#F9A825',
                        'workflow_id' => $workflow->id,
                    ]);
                    NotificationService::send(
                        $user->id,
                        'workflow',
                        'Badge obtenu !',
                        "Vous avez obtenu le badge « " . ($workflow->badge_name ?: $workflow->nom) . " »",
                        'trophy',
                        '#4CAF50'
                    );
                }
                break;

            case 'Ajouter au groupe':
                if ($workflow->target_group_id) {
                    $group = Groupe::find($workflow->target_group_id);
                    if ($group) {
                        // Use the pivot table to add collaborateur to group
                        if (!$group->collaborateurs()->where('collaborateur_id', $collaborateur->id)->exists()) {
                            $group->collaborateurs()->attach($collaborateur->id);
                        }
                        Log::info("Workflow: added {$collabFullName} to group « {$group->nom} »");
                    }
                }
                break;

            case 'Générer un document':
                $docTitle = strtr($workflow->email_subject ?: ($workflow->nom ?: 'Document généré'), $vars);
                $docBody = strtr($workflow->email_body ?: '<p>Document généré automatiquement par le workflow Illizeo.</p>', $vars);
                if (!extension_loaded('gd')) {
                    Log::warning("Workflow '{$workflow->nom}': document generation requires PHP-GD; skipped.");
                    break;
                }
                try {
                    $options = new \Dompdf\Options();
                    $options->set('isHtml5ParserEnabled', true);
                    $options->set('isRemoteEnabled', false);
                    $options->set('defaultFont', 'Helvetica');
                    $dompdf = new \Dompdf\Dompdf($options);
                    $html = '<html><head><meta charset="UTF-8"><style>body{font-family:Helvetica,Arial,sans-serif;color:#1a1a2e;font-size:13px;line-height:1.6;padding:0 20px;}h1{font-size:20px;margin:0 0 16px;color:#1a1a2e;}</style></head><body><h1>' . htmlspecialchars($docTitle) . '</h1>' . $docBody . '</body></html>';
                    $dompdf->loadHtml($html);
                    $dompdf->setPaper('A4', 'portrait');
                    $dompdf->render();
                    $filename = 'workflow-' . $workflow->id . '-' . time() . '.pdf';
                    $path = "documents/{$collaborateur->id}/{$filename}";
                    Storage::disk('local')->put($path, $dompdf->output());
                    $document = Document::create([
                        'nom' => $docTitle,
                        'description' => "Généré par le workflow « {$workflow->nom} »",
                        'obligatoire' => false,
                        'type' => 'genere',
                        'is_template' => false,
                        'status' => 'valide',
                        'collaborateur_id' => $collaborateur->id,
                        'fichier_path' => $path,
                        'fichier_original' => $filename,
                        'fichier_taille' => Storage::disk('local')->size($path),
                        'fichier_mime' => 'application/pdf',
                    ]);
                    if ($user) {
                        NotificationService::send($user->id, 'workflow', 'Document généré', "Le document « {$docTitle} » est disponible dans votre dossier.", 'file', '#1A73E8', ['document_id' => $document->id]);
                    }
                    Log::info("Workflow: generated document #{$document->id} '{$docTitle}' for {$collabFullName}");
                } catch (\Throwable $e) {
                    Log::warning("Workflow '{$workflow->nom}': document generation failed: " . $e->getMessage());
                }
                break;
        }

        Log::info("Workflow executed: {$workflow->nom} (trigger: {$workflow->declencheur})");
    }

    /**
     * Build variable map for template substitution from collaborateur + event.
     */
    private static function buildVariables(Collaborateur $collaborateur, object $event): array
    {
        $manager = DB::table('collaborateur_accompagnants')
            ->where('collaborateur_id', $collaborateur->id)
            ->where('role', 'manager')
            ->join('users', 'users.id', '=', 'collaborateur_accompagnants.user_id')
            ->value('users.name');

        return [
            '{{prenom}}' => $collaborateur->prenom,
            '{{nom}}' => $collaborateur->nom,
            '{{email}}' => $collaborateur->email ?? '',
            '{{date_debut}}' => $collaborateur->date_debut ? \Carbon\Carbon::parse($collaborateur->date_debut)->format('d/m/Y') : '',
            '{{site}}' => $collaborateur->site ?? '',
            '{{poste}}' => $collaborateur->poste ?? '',
            '{{departement}}' => $collaborateur->departement ?? '',
            '{{manager}}' => $manager ?? '',
            '{{parcours_nom}}' => property_exists($event, 'parcoursName') ? $event->parcoursName : ($collaborateur->parcours?->nom ?? 'Onboarding'),
            '{{action_nom}}' => property_exists($event, 'actionTitle') ? $event->actionTitle : '',
            '{{document_nom}}' => property_exists($event, 'documentName') ? $event->documentName : '',
            '{{date_limite}}' => property_exists($event, 'deadline') ? $event->deadline : '',
            '{{nb_docs_manquants}}' => '',
            '{{collab_nom}}' => "{$collaborateur->prenom} {$collaborateur->nom}",
            '{{candidat_nom}}' => property_exists($event, 'candidateName') ? $event->candidateName : '',
            '{{montant}}' => '',
            '{{annees}}' => property_exists($event, 'years') ? (string) $event->years : '',
            '{{date_depart}}' => '',
            '{{date_fin_essai}}' => $collaborateur->date_debut ? \Carbon\Carbon::parse($collaborateur->date_debut)->addMonths(3)->format('d/m/Y') : '',
            '{{lien}}' => env('FRONTEND_URL', 'http://localhost:3000'),
            '{{adresse}}' => '',
            '{{formulaire_nom}}' => property_exists($event, 'formulaireName') ? $event->formulaireName : '',
        ];
    }

    /**
     * Render an HTML email with the Illizeo layout.
     */
    public static function buildHtmlEmail(string $subject, string $body, string $themeColor = '#C2185B'): string
    {
        return self::renderHtmlEmail($subject, $body, $themeColor);
    }

    private static function renderHtmlEmail(string $subject, string $body, string $themeColor = '#C2185B'): string
    {
        // Email templates are authored as HTML by tenant admins (RichEditor),
        // so we render the body verbatim. If a body has no HTML tags, convert
        // newlines to <br> for readability.
        $bodyHtml = preg_match('/<[a-z][^>]*>/i', $body) ? $body : nl2br(htmlspecialchars($body));
        $logoCid = 'cid:' . \App\Mail\Support\TenantLogoEmbedder::CID_NAME;
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'DM Sans',Helvetica,Arial,sans-serif;background:#f5f5fa;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5fa;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);">
  <tr><td style="background:#ffffff;padding:24px 24px 12px;text-align:center;border-bottom:1px solid #E8E8EE;">
    <img src="{$logoCid}" alt="Logo" style="height:44px;width:auto;max-width:240px;display:inline-block;" />
  </td></tr>
  <tr><td style="padding:32px 32px 24px;">
    <div style="font-size:13px;color:#888;margin-bottom:4px;">Sujet</div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:24px;">{$subject}</div>
    <div style="font-size:14px;line-height:1.7;color:#333;">{$bodyHtml}</div>
  </td></tr>
  <tr><td style="padding:16px 32px;border-top:1px solid #E8E8EE;">
    <table width="100%"><tr>
      <td style="text-align:center;">
        <a href="{FRONTEND_URL}" style="display:inline-block;padding:10px 28px;background:{$themeColor};color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Accéder à Illizeo</a>
      </td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:16px 32px;background:#f5f5fa;text-align:center;font-size:11px;color:#aaa;border-top:1px solid #E8E8EE;">
    Cet email a été envoyé automatiquement par Illizeo.<br>
    Vous recevez cet email car vous faites partie d'un parcours d'intégration.
  </td></tr>
</table>
</td></tr></table>
</body>
</html>
HTML;
    }

    /**
     * Send an email to a user — uses EmailTemplate if one matches the trigger, otherwise falls back to provided subject/body.
     */
    private static function sendEmail(int $userId, string $fallbackSubject, string $fallbackBody, ?string $triggerLabel = null, array $variables = []): void
    {
        $user = User::find($userId);
        if (!$user) return;

        // Try to find a matching active template
        $template = null;
        if ($triggerLabel) {
            $template = EmailTemplate::where('declencheur', $triggerLabel)->where('actif', true)->first();
        }

        if ($template && $template->contenu) {
            $subject = strtr($template->sujet, $variables);
            $body = strtr($template->contenu, $variables);
        } else {
            $subject = strtr($fallbackSubject, $variables);
            $body = strtr($fallbackBody, $variables);
        }

        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        if (function_exists('tenant') && tenant()) {
            $frontendUrl .= '/' . tenant()->id;
        }
        $html = str_replace('{FRONTEND_URL}', $frontendUrl, self::renderHtmlEmail($subject, $body));

        try {
            Mail::html($html, function ($message) use ($user, $subject) {
                $message->to($user->email)->subject($subject);
                \App\Mail\Support\TenantLogoEmbedder::embed($message->getSymfonyMessage(), useTenantLogo: true);
            });
        } catch (\Exception $e) {
            Log::warning("Workflow email failed to {$user->email}: " . $e->getMessage());
        }
    }

    /**
     * Resolve recipient user IDs based on the destinataire field.
     */
    private static function resolveRecipients(string $destinataire, Collaborateur $collaborateur, ?Workflow $workflow = null): array
    {
        switch ($destinataire) {
            case 'Collaborateur':
                return $collaborateur->user_id ? [$collaborateur->user_id] : [];

            case 'Manager direct':
                $mid = DB::table('collaborateur_accompagnants')
                    ->where('collaborateur_id', $collaborateur->id)
                    ->where('role', 'manager')
                    ->value('user_id');
                return $mid ? [$mid] : [];

            case 'Équipe RH':
            case 'Admin RH Suisse':
                return User::whereHas('roles', fn ($q) => $q->where('name', 'admin_rh'))->pluck('id')->toArray();

            case 'Tous les participants':
                $ids = DB::table('collaborateur_accompagnants')
                    ->where('collaborateur_id', $collaborateur->id)
                    ->pluck('user_id')
                    ->toArray();
                if ($collaborateur->user_id) {
                    $ids[] = $collaborateur->user_id;
                }
                return array_unique($ids);

            case 'Parrain/Buddy':
                $buddyId = $collaborateur->accompagnants()->whereIn('role', ['buddy', 'parrain'])->value('user_id');
                if ($buddyId) return [$buddyId];
                Log::info("Workflow Parrain/Buddy resolution: no buddy assigned to collaborateur #{$collaborateur->id}.");
                return [];

            case 'N+2':
                // True N+2 = manager's manager. We resolve in three steps :
                //  1. get the N+1 user_id from accompagnants
                //  2. find the Collaborateur record matching that user
                //  3. look up that collaborateur's own manager
                // If any step fails (manager has no fiche, or no manager assigned),
                // fall back to admin_rh users so the workflow doesn't silently die.
                $n1UserId = $collaborateur->accompagnants()->where('role', 'manager')->value('user_id');
                if ($n1UserId) {
                    $n1Collab = Collaborateur::where('user_id', $n1UserId)->first();
                    if ($n1Collab) {
                        $n2UserId = DB::table('collaborateur_accompagnants')
                            ->where('collaborateur_id', $n1Collab->id)
                            ->where('role', 'manager')
                            ->value('user_id');
                        if ($n2UserId) return [$n2UserId];
                    }
                }
                Log::info("Workflow N+2 resolution: no real N+2 found for collaborateur #{$collaborateur->id}, falling back to admin_rh.");
                return User::whereHas('roles', fn ($q) => $q->where('name', 'admin_rh'))->pluck('id')->toArray();

            case 'Utilisateur spécifique':
                return $workflow && $workflow->target_user_id ? [$workflow->target_user_id] : [];

            case 'Groupe spécifique':
                if ($workflow && $workflow->target_group_id) {
                    $group = Groupe::find($workflow->target_group_id);
                    if ($group) {
                        // Find user IDs linked to collaborateurs in this group
                        return $group->collaborateurs()
                            ->whereNotNull('user_id')
                            ->pluck('collaborateurs.user_id')
                            ->toArray();
                    }
                }
                return [];

            default:
                return [];
        }
    }

    /**
     * Build a human-readable description from event properties.
     */
    private static function buildEventDescription(object $event): string
    {
        $parts = [];
        foreach (get_object_vars($event) as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $parts[] = "$key: $value";
            }
        }
        return implode(', ', $parts);
    }
}
