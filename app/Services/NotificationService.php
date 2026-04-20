<?php

namespace App\Services;

use App\Models\UserNotification;

class NotificationService
{
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

    public static function welcome(int $userId, string $prenom, string $parcours): UserNotification
    {
        return self::send($userId, 'welcome', 'Bienvenue !', "Votre parcours « {$parcours} » a commencé. Découvrez vos premières actions.", 'party', '#4CAF50', ['parcours' => $parcours]);
    }

    public static function actionAssigned(int $userId, string $actionTitle, string $delai): UserNotification
    {
        return self::send($userId, 'action_assigned', 'Nouvelle action', "L'action « {$actionTitle} » vous a été assignée (délai : {$delai}).", 'zap', '#1A73E8', ['action' => $actionTitle]);
    }

    public static function reminder(int $userId, string $actionTitle, string $delai): UserNotification
    {
        return self::send($userId, 'reminder', 'Rappel', "L'action « {$actionTitle} » arrive à échéance ({$delai}).", 'clock', '#F9A825', ['action' => $actionTitle]);
    }

    public static function docValidated(int $userId, string $docName): UserNotification
    {
        return self::send($userId, 'doc_validated', 'Document validé', "Votre document « {$docName} » a été validé par l'équipe RH.", 'check', '#4CAF50', ['document' => $docName]);
    }

    public static function docRefused(int $userId, string $docName): UserNotification
    {
        return self::send($userId, 'doc_refused', 'Document refusé', "Votre document « {$docName} » a été refusé. Veuillez le resoumettre.", 'alert', '#E53935', ['document' => $docName]);
    }

    public static function docSubmitted(int $rhUserId, string $collabName, string $docName): UserNotification
    {
        return self::send($rhUserId, 'doc_submitted', 'Document soumis', "{$collabName} a soumis le document « {$docName} ».", 'file', '#1A73E8', ['collaborateur' => $collabName, 'document' => $docName]);
    }

    public static function actionCompleted(int $rhUserId, string $collabName, string $actionTitle): UserNotification
    {
        return self::send($rhUserId, 'action_completed', 'Action complétée', "{$collabName} a terminé l'action « {$actionTitle} ».", 'check', '#4CAF50', ['collaborateur' => $collabName, 'action' => $actionTitle]);
    }

    public static function parcoursCompleted(int $userId, string $prenom, string $parcours): UserNotification
    {
        return self::send($userId, 'parcours_completed', 'Parcours terminé !', "Félicitations {$prenom}, vous avez complété le parcours « {$parcours} » !", 'trophy', '#C2185B', ['parcours' => $parcours]);
    }

    public static function newCollaborateur(int $rhUserId, string $collabName, string $parcours): UserNotification
    {
        return self::send($rhUserId, 'new_collaborateur', 'Nouveau collaborateur', "{$collabName} démarre le parcours « {$parcours} ».", 'user', '#7B5EA7', ['collaborateur' => $collabName, 'parcours' => $parcours]);
    }

    public static function newMessage(int $userId, string $senderName): UserNotification
    {
        return self::send($userId, 'message', 'Nouveau message', "Vous avez reçu un message de {$senderName}.", 'mail', '#1A73E8', ['sender' => $senderName]);
    }

    // ── AI Recharge & Spending Cap ──

    public static function aiAutoRechargeTriggered(int $userId, float $amountChf, int $credits): UserNotification
    {
        return self::send($userId, 'ai_recharge', 'Recharge IA automatique', "{$credits} crédits ajoutés automatiquement ({$amountChf} CHF). Votre plafond a été atteint.", 'zap', '#F9A825', ['amount_chf' => $amountChf, 'credits' => $credits, 'trigger' => 'auto']);
    }

    public static function aiAutoRechargeFailed(int $userId, float $amountChf, string $error): UserNotification
    {
        return self::send($userId, 'ai_recharge_failed', 'Échec recharge IA', "La recharge automatique de {$amountChf} CHF a échoué : {$error}. Vérifiez votre moyen de paiement.", 'alert', '#E53935', ['amount_chf' => $amountChf, 'error' => $error]);
    }

    public static function aiManualRechargeSuccess(int $userId, float $amountChf, int $credits): UserNotification
    {
        return self::send($userId, 'ai_recharge', 'Crédits IA ajoutés', "{$credits} crédits IA achetés pour {$amountChf} CHF. Ils sont immédiatement disponibles.", 'check', '#4CAF50', ['amount_chf' => $amountChf, 'credits' => $credits, 'trigger' => 'manual']);
    }

    public static function aiSpendingCapWarning(int $userId, float $percentUsed, float $capChf, float $currentChf): UserNotification
    {
        $pct = round($percentUsed);
        return self::send($userId, 'ai_cap_warning', 'Plafond IA bientôt atteint', "Votre consommation IA atteint {$pct}% du plafond ({$currentChf} CHF / {$capChf} CHF). Pensez à activer la recharge automatique.", 'alert', '#F9A825', ['percent' => $percentUsed, 'cap_chf' => $capChf, 'current_chf' => $currentChf]);
    }

    public static function aiSpendingCapReached(int $userId, float $capChf): UserNotification
    {
        return self::send($userId, 'ai_cap_reached', 'Plafond IA atteint', "Votre plafond de dépense IA de {$capChf} CHF est atteint. Les fonctionnalités IA sont temporairement bloquées.", 'alert', '#E53935', ['cap_chf' => $capChf]);
    }

    /**
     * Send a notification to all admins of the current tenant.
     */
    public static function notifyAdmins(string $type, string $title, string $content, string $icon = 'bell', string $color = '#C2185B', ?array $data = null): void
    {
        $admins = \App\Models\User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            self::send($admin->id, $type, $title, $content, $icon, $color, $data);
        }
    }
}
