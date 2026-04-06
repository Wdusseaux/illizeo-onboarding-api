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
}
