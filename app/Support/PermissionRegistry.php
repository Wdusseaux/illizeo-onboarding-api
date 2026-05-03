<?php

namespace App\Support;

/**
 * Single source of truth for the permission system.
 *
 * Every entry corresponds to a feature/area that can be gated for a custom
 * role. Levels are: none (no access) < view (read) < edit (write) < admin (full).
 *
 * The frontend admin page Rôles & permissions fetches this registry via
 * GET /api/v1/permissions-registry and renders one toggle per module.
 *
 * Backend enforcement is done by the `permission:<module>,<level>` middleware
 * (see CheckModulePermission). When you add or remove an entry here, also
 * update the routes/controllers that should be gated by that module.
 */
class PermissionRegistry
{
    public const LEVELS = ['none', 'view', 'edit', 'admin'];

    /**
     * Modules grouped by area + section. The order here drives the order in
     * which toggles are displayed in the admin UI.
     */
    public const MODULES = [
        // ═══ ESPACE ADMIN ═══════════════════════════════════════
        // Gestion
        'dashboard_admin'    => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Tableau de bord admin'],
        'parcours'           => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Parcours & Actions'],
        'collaborateurs'     => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Collaborateurs'],
        'manager_view'       => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Vue Manager'],
        'documents'          => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Documents'],
        'equipes'            => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Équipes & Groupes'],
        'calendar'           => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Calendrier'],
        'buddy'              => ['area' => 'admin', 'section' => 'admin_gestion',      'label' => 'Buddy / Parrainage'],

        // Automatisation
        'workflows'          => ['area' => 'admin', 'section' => 'admin_automation',   'label' => 'Workflows'],
        'templates'          => ['area' => 'admin', 'section' => 'admin_automation',   'label' => 'Templates emails'],
        'notifications'      => ['area' => 'admin', 'section' => 'admin_automation',   'label' => 'Notifications'],
        'recurring_meetings' => ['area' => 'admin', 'section' => 'admin_automation',   'label' => 'RDV récurrents'],

        // Contenu
        'company_page'       => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Page entreprise'],
        'bureaux'            => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Bureaux (config)'],
        'quotes'             => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Citations du jour'],
        'equipements'        => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Équipements'],
        'nps'                => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'NPS & Enquêtes'],
        'feedback_hub'       => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Feedback collaborateurs'],
        'contrats'           => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Contrats'],
        'signatures'         => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Signatures'],
        'cooptation'         => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Cooptation'],
        'gamification'       => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Gamification'],
        'projets'            => ['area' => 'admin', 'section' => 'admin_content',      'label' => 'Projets'],

        // Intégrations & Services
        'integrations'       => ['area' => 'admin', 'section' => 'admin_integrations', 'label' => 'Intégrations'],
        'provisioning'       => ['area' => 'admin', 'section' => 'admin_integrations', 'label' => 'Provisioning (SCIM/SSO)'],
        'ai_assistant'       => ['area' => 'admin', 'section' => 'admin_integrations', 'label' => 'Assistant IA'],

        // Sécurité & Paramètres
        'audit'              => ['area' => 'admin', 'section' => 'admin_security',     'label' => "Journal d'audit"],
        'users'              => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Utilisateurs'],
        'roles'              => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Rôles & permissions'],
        'fields'             => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Champs collaborateur'],
        'apparence'          => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Apparence'],
        'security'           => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Sécurité & 2FA'],
        'rgpd'               => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Données & RGPD'],
        'subscription'       => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Abonnement & Billing'],
        'settings'           => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Paramètres généraux'],
        'reports'            => ['area' => 'admin', 'section' => 'admin_security',     'label' => 'Rapports'],

        // ═══ ESPACE COLLABORATEUR ═══════════════════════════════
        'my_dashboard'       => ['area' => 'employe', 'section' => 'emp_workspace',     'label' => 'Mon onboarding (accueil)'],
        'my_actions'         => ['area' => 'employe', 'section' => 'emp_workspace',     'label' => 'Mes actions / Checklist'],
        'my_journey'         => ['area' => 'employe', 'section' => 'emp_workspace',     'label' => 'Mon parcours 100j'],
        'my_team'            => ['area' => 'employe', 'section' => 'emp_workspace',     'label' => 'Mon équipe / Organigramme'],
        'my_signatures'      => ['area' => 'employe', 'section' => 'emp_dossier',       'label' => 'Mes signatures'],
        'my_equipment'       => ['area' => 'employe', 'section' => 'emp_dossier',       'label' => 'Mon matériel'],
        'my_profile'         => ['area' => 'employe', 'section' => 'emp_dossier',       'label' => 'Mon profil'],
        'my_rdv'             => ['area' => 'employe', 'section' => 'emp_dossier',       'label' => 'Mes RDV'],
        'my_badges'          => ['area' => 'employe', 'section' => 'emp_engagement',    'label' => 'Mes badges'],
        'my_offices'         => ['area' => 'employe', 'section' => 'emp_engagement',    'label' => 'Bureaux (tour)'],
        'my_feedback'        => ['area' => 'employe', 'section' => 'emp_engagement',    'label' => 'Satisfaction & feedback'],
        'my_company_page'    => ['area' => 'employe', 'section' => 'emp_engagement',    'label' => 'Page entreprise (lecture)'],
        'my_quotes'          => ['area' => 'employe', 'section' => 'emp_engagement',    'label' => 'Citation du jour'],
        'my_messaging'       => ['area' => 'employe', 'section' => 'emp_communication', 'label' => 'Messagerie'],
        'my_assistant'       => ['area' => 'employe', 'section' => 'emp_communication', 'label' => 'Assistant IA (collab)'],
        'my_cooptation'      => ['area' => 'employe', 'section' => 'emp_communication', 'label' => 'Cooptation (soumettre)'],
    ];

    /**
     * Section labels for display grouping in the admin UI.
     */
    public const SECTIONS = [
        'admin_gestion'      => 'Gestion',
        'admin_automation'   => 'Automatisation',
        'admin_content'      => 'Contenu',
        'admin_integrations' => 'Intégrations & Services',
        'admin_security'     => 'Sécurité & Paramètres',
        'emp_workspace'      => 'Mon espace',
        'emp_dossier'        => 'Mon dossier',
        'emp_engagement'     => 'Engagement & Découverte',
        'emp_communication'  => 'Communication',
    ];

    /**
     * List of valid module keys.
     */
    public static function moduleKeys(): array
    {
        return array_keys(self::MODULES);
    }

    /**
     * Returns the registry as a structured array suitable for the frontend.
     */
    public static function toArray(): array
    {
        $modules = [];
        foreach (self::MODULES as $key => $meta) {
            $modules[] = array_merge(['key' => $key], $meta);
        }
        return [
            'levels'   => self::LEVELS,
            'sections' => self::SECTIONS,
            'modules'  => $modules,
        ];
    }
}
