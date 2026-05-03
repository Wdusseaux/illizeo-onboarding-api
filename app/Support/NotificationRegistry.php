<?php

namespace App\Support;

use App\Models\CompanySetting;

/**
 * Single source of truth for notification types.
 *
 * Each entry mirrors what NotificationService actually emits — adding a
 * notification to the system requires:
 *   1. Adding a method to NotificationService that calls send/sendWithEmail with this type key.
 *   2. Adding the same key here so the admin UI can expose a toggle.
 *
 * The "channels" array lists which delivery channels are technically supported
 * by the notification (email is opt-in; in-app is the default). The "default"
 * array sets the out-of-the-box state of the toggles before any admin action.
 */
class NotificationRegistry
{
    /**
     * Channels supported across the platform.
     */
    public const CHANNELS = ['inapp', 'email'];

    /**
     * The complete set of notifications the backend can emit.
     */
    public const TYPES = [
        // ── Onboarding — collaborateur side ─────────────────────
        'welcome' => [
            'label' => 'Bienvenue — Démarrage du parcours',
            'description' => 'Envoyé au collaborateur lorsqu\'un parcours d\'onboarding lui est attribué.',
            'category' => 'onboarding',
            'audience' => 'collaborateur',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'action_assigned' => [
            'label' => 'Nouvelle tâche assignée',
            'description' => 'Envoyé au collaborateur lorsqu\'une action est créée sur son parcours.',
            'category' => 'onboarding',
            'audience' => 'collaborateur',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'reminder' => [
            'label' => 'Relance — Échéance proche',
            'description' => 'Envoyé au collaborateur quelques jours avant l\'échéance d\'une action.',
            'category' => 'onboarding',
            'audience' => 'collaborateur',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'doc_validated' => [
            'label' => 'Document validé',
            'description' => 'Envoyé au collaborateur lorsque l\'équipe RH valide un document soumis.',
            'category' => 'documents',
            'audience' => 'collaborateur',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'doc_refused' => [
            'label' => 'Document refusé — action requise',
            'description' => 'Envoyé au collaborateur lorsqu\'un document est refusé et doit être resoumis.',
            'category' => 'documents',
            'audience' => 'collaborateur',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'parcours_completed' => [
            'label' => 'Parcours terminé — Félicitations',
            'description' => 'Envoyé au collaborateur lorsque toutes les étapes du parcours sont complétées.',
            'category' => 'onboarding',
            'audience' => 'collaborateur',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],

        // ── Onboarding — RH side ────────────────────────────────
        'doc_submitted' => [
            'label' => 'Document soumis — validation requise',
            'description' => 'Envoyé à l\'équipe RH quand un collaborateur soumet un document à valider.',
            'category' => 'documents',
            'audience' => 'rh',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'action_completed' => [
            'label' => 'Action complétée par un collaborateur',
            'description' => 'Envoyé à l\'équipe RH lorsqu\'un collaborateur termine une action.',
            'category' => 'onboarding',
            'audience' => 'rh',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => false],
        ],
        'new_collaborateur' => [
            'label' => 'Nouveau collaborateur démarre',
            'description' => 'Envoyé à l\'équipe RH lorsqu\'un nouveau collaborateur démarre un parcours.',
            'category' => 'onboarding',
            'audience' => 'rh',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],

        // ── Messagerie ──────────────────────────────────────────
        'message' => [
            'label' => 'Nouveau message reçu',
            'description' => 'Notification in-app uniquement (l\'email serait trop fréquent).',
            'category' => 'communication',
            'audience' => 'tous',
            'channels' => ['inapp'],
            'default' => ['inapp' => true],
        ],

        // ── Facturation IA ──────────────────────────────────────
        'ai_recharge' => [
            'label' => 'Recharge IA effectuée',
            'description' => 'Envoyé à l\'admin lors d\'une recharge IA (auto ou manuelle).',
            'category' => 'facturation',
            'audience' => 'admin',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'ai_recharge_failed' => [
            'label' => 'Échec recharge IA — action requise',
            'description' => 'Envoyé à l\'admin si la recharge automatique IA échoue.',
            'category' => 'facturation',
            'audience' => 'admin',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'ai_cap_warning' => [
            'label' => 'Plafond IA bientôt atteint',
            'description' => 'Envoyé à l\'admin quand la consommation IA atteint ~80% du plafond.',
            'category' => 'facturation',
            'audience' => 'admin',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'ai_cap_reached' => [
            'label' => 'Plafond IA atteint — fonctionnalités bloquées',
            'description' => 'Envoyé à l\'admin quand le plafond IA est atteint et bloque les fonctionnalités IA.',
            'category' => 'facturation',
            'audience' => 'admin',
            'channels' => ['inapp', 'email'],
            'default' => ['inapp' => true, 'email' => true],
        ],
        'ai_suggestion' => [
            'label' => 'Suggestion IA proactive',
            'description' => 'Suggestions générées par l\'IA pour les admins (NPS détracteurs, alertes parcours…).',
            'category' => 'facturation',
            'audience' => 'admin',
            'channels' => ['inapp'],
            'default' => ['inapp' => true],
        ],

        // ── Engagement / Gamification ───────────────────────────
        'badge_earned' => [
            'label' => 'Badge obtenu',
            'description' => 'Envoyé au collaborateur lorsqu\'un badge lui est attribué.',
            'category' => 'gamification',
            'audience' => 'collaborateur',
            'channels' => ['inapp'],
            'default' => ['inapp' => true],
        ],

        // ── Cooptation ──────────────────────────────────────────
        'cooptation' => [
            'label' => 'Statut cooptation mis à jour',
            'description' => 'Envoyé au coopteur quand sa cooptation change de statut (validée, embauchée, refusée…).',
            'category' => 'cooptation',
            'audience' => 'collaborateur',
            'channels' => ['inapp'],
            'default' => ['inapp' => true],
        ],

        // ── Signatures ──────────────────────────────────────────
        'document' => [
            'label' => 'Document à signer / lire',
            'description' => 'Envoyé au collaborateur lorsqu\'un document de signature ou lecture lui est envoyé.',
            'category' => 'documents',
            'audience' => 'collaborateur',
            'channels' => ['inapp'],
            'default' => ['inapp' => true],
        ],

        // ── Workflow générique ──────────────────────────────────
        'workflow' => [
            'label' => 'Action de workflow',
            'description' => 'Notifications déclenchées par les workflows admin (validations, signatures, génération de doc, etc.).',
            'category' => 'workflow',
            'audience' => 'tous',
            'channels' => ['inapp'],
            'default' => ['inapp' => true],
        ],

        // ── Dossier ─────────────────────────────────────────────
        'dossier' => [
            'label' => 'Dossier validé / exporté',
            'description' => 'Confirmation envoyée à l\'auteur de l\'action lorsqu\'un dossier collaborateur est validé pour le SIRH.',
            'category' => 'dossier',
            'audience' => 'rh',
            'channels' => ['inapp'],
            'default' => ['inapp' => true],
        ],
    ];

    /**
     * Whether a given (type, channel) is enabled for the current tenant.
     * Falls back to the type's default if not configured.
     */
    public static function isEnabled(string $type, string $channel): bool
    {
        // Unknown types default to ENABLED — never silently swallow a notification
        // that hasn't been registered yet.
        if (!isset(self::TYPES[$type])) {
            return true;
        }

        $meta = self::TYPES[$type];

        // Channel not supported by this notification type → always disabled.
        if (!in_array($channel, $meta['channels'] ?? [], true)) {
            return false;
        }

        try {
            $raw = CompanySetting::where('key', 'notif_config')->value('value');
        } catch (\Throwable) {
            $raw = null;
        }

        $cfg = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }

        if (isset($cfg[$type][$channel])) {
            return (bool) $cfg[$type][$channel];
        }

        return (bool) ($meta['default'][$channel] ?? false);
    }

    /**
     * Returns the registry as a plain array for the frontend.
     */
    public static function toArray(): array
    {
        $list = [];
        foreach (self::TYPES as $key => $meta) {
            $list[] = array_merge(['key' => $key], $meta);
        }
        return $list;
    }
}
