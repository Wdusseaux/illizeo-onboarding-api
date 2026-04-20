<?php

namespace App\Services;

use App\Events\ActionCompleted;
use App\Events\AllDocumentsValidated;
use App\Events\AnniversaireEmbauche;
use App\Events\CollaborateurEnRetard;
use App\Events\ContratReady;
use App\Events\ContratSigned;
use App\Events\CooptationValidated;
use App\Events\DeadlineApproaching;
use App\Events\DocumentRefused;
use App\Events\DocumentSubmitted;
use App\Events\DocumentValidated;
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
use App\Events\SignatureReminder;
use App\Events\WeeklyDigest;
use App\Models\Badge;
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
        PostArrivalMilestone::class => 'Milestone post-arrivée',
        PeriodeEssaiTerminee::class => 'Période d\'essai terminée',
        AnniversaireEmbauche::class => 'Anniversaire d\'embauche',
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
                $adminRhIds = User::role('admin_rh')->pluck('id');
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
                if ($user) {
                    NotificationService::actionAssigned($user->id, 'Nouvelle action assignée automatiquement', 'Workflow');
                }
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
                try {
                    $integration = Integration::where('provider', 'teams')->where('actif', true)->first();
                    if ($integration && !empty($integration->config['webhook_url'])) {
                        $teamsService = TeamsService::fromIntegration($integration);
                        $teamsService->sendWebhookCard($workflow->nom, self::buildEventDescription($event));
                    }
                } catch (\Exception $e) {
                    Log::warning("Teams workflow failed: " . $e->getMessage());
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
                if ($user) {
                    NotificationService::send($user->id, 'workflow', 'Document généré', 'Un nouveau document a été généré pour votre dossier', 'file', '#1A73E8');
                }
                Log::info("Workflow: document generation triggered for {$collabFullName} — needs DomPDF template");
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
        $bodyHtml = nl2br(htmlspecialchars($body));
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'DM Sans',Helvetica,Arial,sans-serif;background:#f5f5fa;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5fa;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);">
  <tr><td style="background:{$themeColor};padding:20px 24px;text-align:center;">
    <span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">ILLIZEO</span>
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

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $html = str_replace('{FRONTEND_URL}', $frontendUrl, self::renderHtmlEmail($subject, $body));

        try {
            Mail::html($html, function ($message) use ($user, $subject) {
                $message->to($user->email)->subject($subject);
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
                return User::role('admin_rh')->pluck('id')->toArray();

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
                $buddyId = $collaborateur->accompagnants()->where('role', 'buddy')->value('user_id');
                return $buddyId ? [$buddyId] : [];

            case 'N+2':
                // Get manager's manager — simplified: get all admin_rh users as N+2 proxy
                $managerId = $collaborateur->accompagnants()->where('role', 'manager')->value('user_id');
                if ($managerId) {
                    return User::role('admin_rh')->pluck('id')->toArray();
                }
                return [];

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
