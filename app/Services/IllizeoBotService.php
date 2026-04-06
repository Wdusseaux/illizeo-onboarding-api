<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class IllizeoBotService
{
    /**
     * Send a bot message to a user
     */
    public static function sendTo(int $userId, string $content, string $botType = 'system'): Message
    {
        // Bot uses user ID 0 (system) — we use participant_1 = min, participant_2 = max
        // For bot messages, we create a special conversation with the first admin user
        $botUser = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_rh'))->first()
            ?? User::first();

        if (!$botUser) {
            throw new \Exception('No bot user available');
        }

        $conversation = Conversation::findOrCreateBetween($botUser->id, $userId);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => null, // null = bot
            'content' => $content,
            'is_bot' => true,
            'bot_type' => $botType,
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * Welcome message when onboarding starts
     */
    public static function sendWelcome(int $userId, string $prenom, string $parcours): Message
    {
        return self::sendTo($userId,
            "👋 Bienvenue {$prenom} !\n\n"
            . "Je suis IllizeoBot, votre assistant d'intégration. "
            . "Votre parcours « {$parcours} » vient de commencer.\n\n"
            . "Je vous guiderai à chaque étape : documents à fournir, formations à suivre, personnes à rencontrer.\n\n"
            . "N'hésitez pas à poser vos questions ici, votre équipe RH vous répondra rapidement ! 🚀",
            'welcome'
        );
    }

    /**
     * Reminder for upcoming action
     */
    public static function sendReminder(int $userId, string $actionTitle, string $delai): Message
    {
        return self::sendTo($userId,
            "⏰ Rappel : l'action « {$actionTitle} » arrive à échéance ({$delai}).\n\n"
            . "Pensez à la compléter depuis votre tableau de bord.",
            'reminder'
        );
    }

    /**
     * Congratulations for completing an action
     */
    public static function sendActionCompleted(int $userId, string $actionTitle): Message
    {
        return self::sendTo($userId,
            "✅ Bravo ! Vous avez complété « {$actionTitle} ».\n\nContinuez comme ça ! 💪",
            'congrats'
        );
    }

    /**
     * Document status notification
     */
    public static function sendDocumentStatus(int $userId, string $docName, string $status): Message
    {
        $emoji = $status === 'valide' ? '✅' : ($status === 'refuse' ? '❌' : '📄');
        $text = $status === 'valide'
            ? "Votre document « {$docName} » a été validé par l'équipe RH."
            : ($status === 'refuse'
                ? "Votre document « {$docName} » a été refusé. Veuillez le resoumettre."
                : "Votre document « {$docName} » a été reçu et est en cours de vérification.");

        return self::sendTo($userId, "{$emoji} {$text}", 'alert');
    }

    /**
     * Parcours completed
     */
    public static function sendParcoursCompleted(int $userId, string $prenom, string $parcours): Message
    {
        return self::sendTo($userId,
            "🏆 Félicitations {$prenom} !\n\n"
            . "Vous avez terminé votre parcours « {$parcours} » avec succès.\n\n"
            . "Bienvenue dans l'équipe ! 🎉",
            'congrats'
        );
    }
}
