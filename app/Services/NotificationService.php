<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Send in-app notification + optional email.
     */
    public static function send(int $userId, string $type, string $title, string $content, string $icon = 'bell', string $color = '#C2185B', ?array $data = null): UserNotification
    {
        return UserNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'icon' => $icon,
            'color' => $color,
            'data' => $data,
        ]);
    }

    /**
     * Send in-app notification + email to user.
     */
    public static function sendWithEmail(int $userId, string $type, string $title, string $content, string $icon = 'bell', string $color = '#C2185B', ?array $data = null, string $ctaLabel = '', string $ctaUrl = ''): UserNotification
    {
        $notif = self::send($userId, $type, $title, $content, $icon, $color, $data);

        try {
            $user = \App\Models\User::find($userId);
            if ($user?->email) {
                $name = trim(($user->prenom ?? '') . ' ' . ($user->nom ?? '')) ?: 'Utilisateur';
                Mail::to($user->email)->send(new NotificationMail(
                    recipientName: $name,
                    emailSubject: $title . ' — Illizeo',
                    heading: $title,
                    body: $content,
                    ctaLabel: $ctaLabel,
                    ctaUrl: $ctaUrl,
                    accentColor: $color,
                ));
            }
        } catch (\Exception $e) {
            Log::warning("Failed to send notification email: {$e->getMessage()}", ['user_id' => $userId, 'type' => $type]);
        }

        return $notif;
    }

    /**
     * Send notification + email to all admins.
     */
    public static function notifyAdminsWithEmail(string $type, string $title, string $content, string $icon = 'bell', string $color = '#C2185B', ?array $data = null, string $ctaLabel = '', string $ctaUrl = ''): void
    {
        $admins = \App\Models\User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            self::sendWithEmail($admin->id, $type, $title, $content, $icon, $color, $data, $ctaLabel, $ctaUrl);
        }
    }

    // ── Onboarding — with email ──

    public static function welcome(int $userId, string $prenom, string $parcours): UserNotification
    {
        return self::sendWithEmail($userId, 'welcome', 'Bienvenue chez nous !',
            "Bonjour {$prenom}, votre parcours d'intégration « {$parcours} » a commencé. Connectez-vous pour découvrir vos premières actions et les documents à fournir.",
            'party', '#4CAF50', ['parcours' => $parcours],
            'Accéder à mon parcours', self::appUrl());
    }

    public static function actionAssigned(int $userId, string $actionTitle, string $delai): UserNotification
    {
        return self::sendWithEmail($userId, 'action_assigned', 'Nouvelle action à réaliser',
            "L'action « {$actionTitle} » vous a été assignée. Délai : {$delai}.",
            'zap', '#1A73E8', ['action' => $actionTitle],
            'Voir l\'action', self::appUrl());
    }

    public static function reminder(int $userId, string $actionTitle, string $delai): UserNotification
    {
        return self::sendWithEmail($userId, 'reminder', 'Rappel — Échéance proche',
            "L'action « {$actionTitle} » arrive à échéance ({$delai}). Pensez à la compléter rapidement.",
            'clock', '#F9A825', ['action' => $actionTitle],
            'Compléter l\'action', self::appUrl());
    }

    public static function docValidated(int $userId, string $docName): UserNotification
    {
        return self::sendWithEmail($userId, 'doc_validated', 'Document validé',
            "Votre document « {$docName} » a été validé par l'équipe RH. Merci !",
            'check', '#4CAF50', ['document' => $docName]);
    }

    public static function docRefused(int $userId, string $docName): UserNotification
    {
        return self::sendWithEmail($userId, 'doc_refused', 'Document refusé — action requise',
            "Votre document « {$docName} » a été refusé par l'équipe RH. Veuillez le vérifier et le resoumettre dès que possible.",
            'alert', '#E53935', ['document' => $docName],
            'Resoumettre le document', self::appUrl());
    }

    public static function docSubmitted(int $rhUserId, string $collabName, string $docName): UserNotification
    {
        return self::sendWithEmail($rhUserId, 'doc_submitted', 'Document soumis — validation requise',
            "{$collabName} a soumis le document « {$docName} ». Il est en attente de votre validation.",
            'file', '#1A73E8', ['collaborateur' => $collabName, 'document' => $docName],
            'Valider le document', self::appUrl());
    }

    public static function actionCompleted(int $rhUserId, string $collabName, string $actionTitle): UserNotification
    {
        return self::sendWithEmail($rhUserId, 'action_completed', 'Action complétée',
            "{$collabName} a terminé l'action « {$actionTitle} ».",
            'check', '#4CAF50', ['collaborateur' => $collabName, 'action' => $actionTitle]);
    }

    public static function parcoursCompleted(int $userId, string $prenom, string $parcours): UserNotification
    {
        return self::sendWithEmail($userId, 'parcours_completed', 'Parcours terminé — Félicitations !',
            "Bravo {$prenom} ! Vous avez complété l'ensemble du parcours « {$parcours} ». Merci pour votre investissement.",
            'trophy', '#C2185B', ['parcours' => $parcours]);
    }

    public static function newCollaborateur(int $rhUserId, string $collabName, string $parcours): UserNotification
    {
        return self::sendWithEmail($rhUserId, 'new_collaborateur', 'Nouveau collaborateur',
            "{$collabName} vient de démarrer le parcours « {$parcours} ». Pensez à vérifier les actions et documents associés.",
            'user', '#7B5EA7', ['collaborateur' => $collabName, 'parcours' => $parcours],
            'Voir le suivi', self::appUrl());
    }

    public static function newMessage(int $userId, string $senderName): UserNotification
    {
        return self::send($userId, 'message', 'Nouveau message',
            "Vous avez reçu un message de {$senderName}.",
            'mail', '#1A73E8', ['sender' => $senderName]);
        // No email for messages — too frequent
    }

    // ── AI Recharge & Spending Cap — with email for critical ones ──

    public static function aiAutoRechargeTriggered(int $userId, float $amountChf, int $credits): UserNotification
    {
        return self::sendWithEmail($userId, 'ai_recharge', 'Recharge IA automatique',
            "{$credits} crédits IA ont été ajoutés automatiquement ({$amountChf} CHF débités). Votre plafond de consommation avait été atteint.",
            'zap', '#F9A825', ['amount_chf' => $amountChf, 'credits' => $credits, 'trigger' => 'auto'],
            'Voir la consommation IA', self::appUrl());
    }

    public static function aiAutoRechargeFailed(int $userId, float $amountChf, string $error): UserNotification
    {
        return self::sendWithEmail($userId, 'ai_recharge_failed', 'Échec recharge IA — action requise',
            "La recharge automatique de {$amountChf} CHF a échoué ({$error}). Vos fonctionnalités IA risquent d'être bloquées. Veuillez vérifier votre moyen de paiement.",
            'alert', '#E53935', ['amount_chf' => $amountChf, 'error' => $error],
            'Vérifier le paiement', self::appUrl());
    }

    public static function aiManualRechargeSuccess(int $userId, float $amountChf, int $credits): UserNotification
    {
        return self::sendWithEmail($userId, 'ai_recharge', 'Crédits IA ajoutés',
            "{$credits} crédits IA achetés pour {$amountChf} CHF. Ils sont immédiatement disponibles.",
            'check', '#4CAF50', ['amount_chf' => $amountChf, 'credits' => $credits, 'trigger' => 'manual']);
    }

    public static function aiSpendingCapWarning(int $userId, float $percentUsed, float $capChf, float $currentChf): UserNotification
    {
        $pct = round($percentUsed);
        return self::sendWithEmail($userId, 'ai_cap_warning', 'Plafond IA bientôt atteint',
            "Votre consommation IA atteint {$pct}% du plafond ({$currentChf} CHF / {$capChf} CHF). Pensez à activer la recharge automatique ou à augmenter votre plafond.",
            'alert', '#F9A825', ['percent' => $percentUsed, 'cap_chf' => $capChf, 'current_chf' => $currentChf],
            'Gérer le plafond', self::appUrl());
    }

    public static function aiSpendingCapReached(int $userId, float $capChf): UserNotification
    {
        return self::sendWithEmail($userId, 'ai_cap_reached', 'Plafond IA atteint — fonctionnalités bloquées',
            "Votre plafond de dépense IA de {$capChf} CHF est atteint. Les fonctionnalités IA sont temporairement bloquées. Augmentez votre plafond ou achetez des crédits supplémentaires.",
            'alert', '#E53935', ['cap_chf' => $capChf],
            'Augmenter le plafond', self::appUrl());
    }

    // ── Helpers ──

    /**
     * Send a notification to all admins of the current tenant (in-app only).
     */
    public static function notifyAdmins(string $type, string $title, string $content, string $icon = 'bell', string $color = '#C2185B', ?array $data = null): void
    {
        $admins = \App\Models\User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            self::send($admin->id, $type, $title, $content, $icon, $color, $data);
        }
    }

    /**
     * Get the app base URL for CTA links.
     */
    private static function appUrl(): string
    {
        $tenant = tenant();
        $slug = $tenant?->id ?? 'app';
        return "https://onboarding.illizeo.com/{$slug}";
    }
}
