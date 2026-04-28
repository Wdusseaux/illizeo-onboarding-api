<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\ActionType;
use App\Models\CollaborateurFieldConfig;
use App\Models\CompanyBlock;
use App\Models\Cooptation;
use App\Models\CooptationCampaign;
use App\Models\CooptationPoint;
use App\Models\CooptationSetting;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Collaborateur;
use App\Models\Contrat;
use App\Models\DocumentCategorie;
use App\Models\Document;
use App\Models\EmailTemplate;
use App\Models\Groupe;
use App\Models\NotificationConfig;
use App\Models\Parcours;
use App\Models\ParcoursCategorie;
use App\Models\Phase;
use App\Models\SignatureDocument;
use App\Models\Workflow;
use Illuminate\Database\Seeder;

class IllizeoSeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing content to allow re-seeding
        $truncate = ['cooptation_points','cooptations','cooptation_campaigns','cooptation_settings',
            'nps_responses','nps_surveys','company_blocks','collaborateur_field_config',
            'document_acknowledgements','signature_documents','signature_logs','documents','document_categories',
            'contrats','email_templates','workflows','notifications_config',
            'collaborateur_actions','collaborateur_accompagnants','collaborateur_groupe',
            'groupes','actions','phases','parcours','parcours_categories','action_types',
            'integrations','equipments','equipment_packages','equipment_package_items','equipment_types',
            'badge_templates','badges','onboarding_team_members','onboarding_teams',
            'messages','conversations','user_notifications','collaborateurs'];
        foreach ($truncate as $t) {
            try { \Illuminate\Support\Facades\DB::table($t)->delete(); } catch (\Exception $e) {}
        }

        // ── 1. Parcours Categories ──────────────────────────────
        $categories = [];
        foreach ([
            ['slug' => 'onboarding', 'nom' => 'Onboarding', 'description' => 'Intégration des nouveaux collaborateurs', 'couleur' => '#4CAF50'],
            ['slug' => 'offboarding', 'nom' => 'Offboarding', 'description' => 'Gestion des départs', 'couleur' => '#E53935'],
            ['slug' => 'crossboarding', 'nom' => 'Crossboarding', 'description' => 'Mobilité interne', 'couleur' => '#1A73E8'],
            ['slug' => 'reboarding', 'nom' => 'Reboarding', 'description' => 'Retour après absence', 'couleur' => '#F9A825'],
        ] as $cat) {
            $categories[$cat['slug']] = ParcoursCategorie::create($cat);
        }

        // ── 2. Parcours Templates ───────────────────────────────
        $parcoursData = [
            ['nom' => 'Onboarding Standard', 'categorie' => 'onboarding', 'actions_count' => 12, 'docs_count' => 22, 'collaborateurs_actifs' => 4, 'status' => 'actif'],
            ['nom' => 'Onboarding Cadres', 'categorie' => 'onboarding', 'actions_count' => 18, 'docs_count' => 28, 'collaborateurs_actifs' => 1, 'status' => 'actif'],
            ['nom' => 'Onboarding Stagiaires', 'categorie' => 'onboarding', 'actions_count' => 6, 'docs_count' => 8, 'collaborateurs_actifs' => 0, 'status' => 'brouillon'],
            ['nom' => 'Départ standard', 'categorie' => 'offboarding', 'actions_count' => 14, 'docs_count' => 6, 'collaborateurs_actifs' => 2, 'status' => 'actif'],
            ['nom' => 'Départ retraite', 'categorie' => 'offboarding', 'actions_count' => 18, 'docs_count' => 8, 'collaborateurs_actifs' => 1, 'status' => 'actif'],
            ['nom' => 'Fin de contrat', 'categorie' => 'offboarding', 'actions_count' => 8, 'docs_count' => 4, 'collaborateurs_actifs' => 0, 'status' => 'actif'],
            ['nom' => 'Mobilité interne standard', 'categorie' => 'crossboarding', 'actions_count' => 10, 'docs_count' => 3, 'collaborateurs_actifs' => 1, 'status' => 'actif'],
            ['nom' => 'Promotion managériale', 'categorie' => 'crossboarding', 'actions_count' => 14, 'docs_count' => 5, 'collaborateurs_actifs' => 0, 'status' => 'brouillon'],
            ['nom' => 'Retour congé maternité/parental', 'categorie' => 'reboarding', 'actions_count' => 8, 'docs_count' => 2, 'collaborateurs_actifs' => 1, 'status' => 'actif'],
            ['nom' => 'Retour maladie longue durée', 'categorie' => 'reboarding', 'actions_count' => 12, 'docs_count' => 4, 'collaborateurs_actifs' => 0, 'status' => 'actif'],
        ];

        $parcours = [];
        foreach ($parcoursData as $p) {
            $parcours[$p['nom']] = Parcours::create([
                'nom' => $p['nom'],
                'categorie_id' => $categories[$p['categorie']]->id,
                'actions_count' => $p['actions_count'],
                'docs_count' => $p['docs_count'],
                'collaborateurs_actifs' => $p['collaborateurs_actifs'],
                'status' => $p['status'],
            ]);
        }

        // ── 3. Phases ───────────────────────────────────────────
        $phasesData = [
            ['nom' => 'Avant le premier jour', 'delai_debut' => 'J-30', 'delai_fin' => 'J-1', 'couleur' => '#4CAF50', 'icone' => 'Hand', 'actions_defaut' => 4, 'ordre' => 1, 'parcours' => 'Onboarding Standard'],
            ['nom' => 'Premier jour', 'delai_debut' => 'J+0', 'delai_fin' => 'J+0', 'couleur' => '#1A73E8', 'icone' => 'PartyPopper', 'actions_defaut' => 3, 'ordre' => 2, 'parcours' => 'Onboarding Standard'],
            ['nom' => 'Première semaine', 'delai_debut' => 'J+1', 'delai_fin' => 'J+7', 'couleur' => '#F9A825', 'icone' => 'Dumbbell', 'actions_defaut' => 3, 'ordre' => 3, 'parcours' => 'Onboarding Standard'],
            ['nom' => '3 premiers mois', 'delai_debut' => 'J+8', 'delai_fin' => 'J+90', 'couleur' => '#C2185B', 'icone' => 'Package', 'actions_defaut' => 2, 'ordre' => 4, 'parcours' => 'Onboarding Standard'],
            // Offboarding phases
            ['nom' => 'Annonce', 'delai_debut' => 'J-30', 'delai_fin' => 'J-14', 'couleur' => '#E53935', 'icone' => 'Bell', 'actions_defaut' => 2, 'ordre' => 1, 'parcours' => 'Départ standard'],
            ['nom' => 'Transition', 'delai_debut' => 'J-14', 'delai_fin' => 'J-1', 'couleur' => '#F9A825', 'icone' => 'ArrowRight', 'actions_defaut' => 3, 'ordre' => 2, 'parcours' => 'Départ standard'],
            ['nom' => 'Dernier jour', 'delai_debut' => 'J+0', 'delai_fin' => 'J+0', 'couleur' => '#7B5EA7', 'icone' => 'LogOut', 'actions_defaut' => 2, 'ordre' => 3, 'parcours' => 'Départ standard'],
            ['nom' => 'Post-départ', 'delai_debut' => 'J+1', 'delai_fin' => 'J+30', 'couleur' => '#888', 'icone' => 'Mail', 'actions_defaut' => 2, 'ordre' => 4, 'parcours' => 'Départ standard'],
            // Crossboarding phases
            ['nom' => 'Annonce mobilité', 'delai_debut' => 'J-30', 'delai_fin' => 'J-14', 'couleur' => '#1A73E8', 'icone' => 'Navigation', 'actions_defaut' => 1, 'ordre' => 1, 'parcours' => 'Mobilité interne standard'],
            ['nom' => 'Transition poste', 'delai_debut' => 'J-14', 'delai_fin' => 'J-1', 'couleur' => '#F9A825', 'icone' => 'Route', 'actions_defaut' => 2, 'ordre' => 2, 'parcours' => 'Mobilité interne standard'],
            ['nom' => 'Intégration équipe', 'delai_debut' => 'J+0', 'delai_fin' => 'J+7', 'couleur' => '#4CAF50', 'icone' => 'Users', 'actions_defaut' => 1, 'ordre' => 3, 'parcours' => 'Mobilité interne standard'],
            ['nom' => 'Suivi J+30', 'delai_debut' => 'J+8', 'delai_fin' => 'J+30', 'couleur' => '#C2185B', 'icone' => 'Target', 'actions_defaut' => 1, 'ordre' => 4, 'parcours' => 'Mobilité interne standard'],
            // Reboarding phases
            ['nom' => 'Pré-retour J-14', 'delai_debut' => 'J-14', 'delai_fin' => 'J-1', 'couleur' => '#4CAF50', 'icone' => 'Calendar', 'actions_defaut' => 1, 'ordre' => 1, 'parcours' => 'Retour congé maternité/parental'],
            ['nom' => 'Jour de retour', 'delai_debut' => 'J+0', 'delai_fin' => 'J+0', 'couleur' => '#1A73E8', 'icone' => 'PartyPopper', 'actions_defaut' => 2, 'ordre' => 2, 'parcours' => 'Retour congé maternité/parental'],
            ['nom' => 'Réintégration', 'delai_debut' => 'J+1', 'delai_fin' => 'J+30', 'couleur' => '#F9A825', 'icone' => 'Target', 'actions_defaut' => 1, 'ordre' => 3, 'parcours' => 'Retour congé maternité/parental'],
        ];

        $phases = [];
        foreach ($phasesData as $ph) {
            $parcoursNom = $ph['parcours'];
            unset($ph['parcours']);
            $parcoursId = $parcours[$parcoursNom]->id;
            $ph['parcours_id'] = $parcoursId; // keep legacy column
            $phase = Phase::create($ph);
            $phase->parcours()->attach($parcoursId, ['ordre' => $ph['ordre']]);
            $phases[$ph['nom']] = $phase;
        }

        // ── 4. Action Types ─────────────────────────────────────
        $actionTypesData = [
            ['slug' => 'document', 'label' => 'Document', 'icone' => 'FileUp', 'couleur_bg' => '#E3F2FD', 'couleur_texte' => '#1A73E8'],
            ['slug' => 'formulaire', 'label' => 'Formulaire', 'icone' => 'ClipboardList', 'couleur_bg' => '#FFF0F5', 'couleur_texte' => '#C2185B'],
            ['slug' => 'formation', 'label' => 'Formation', 'icone' => 'GraduationCap', 'couleur_bg' => '#E8F5E9', 'couleur_texte' => '#4CAF50'],
            ['slug' => 'questionnaire', 'label' => 'Questionnaire', 'icone' => 'ListChecks', 'couleur_bg' => '#F3E5F5', 'couleur_texte' => '#7B5EA7'],
            ['slug' => 'tache', 'label' => 'Tâche', 'icone' => 'ShieldCheck', 'couleur_bg' => '#E8F5E9', 'couleur_texte' => '#388E3C'],
            ['slug' => 'signature', 'label' => 'Signature', 'icone' => 'PenTool', 'couleur_bg' => '#FFF8E1', 'couleur_texte' => '#F9A825'],
            ['slug' => 'lecture', 'label' => 'Lecture', 'icone' => 'BookOpen', 'couleur_bg' => '#E8EAF6', 'couleur_texte' => '#3949AB'],
            ['slug' => 'rdv', 'label' => 'Rendez-vous', 'icone' => 'CalendarClock', 'couleur_bg' => '#FCE4EC', 'couleur_texte' => '#D81B60'],
            ['slug' => 'message', 'label' => 'Message', 'icone' => 'MessageSquare', 'couleur_bg' => '#E0F7FA', 'couleur_texte' => '#00897B'],
            ['slug' => 'entretien', 'label' => 'Entretien', 'icone' => 'MessageCircle', 'couleur_bg' => '#FFF3E0', 'couleur_texte' => '#E65100'],
            ['slug' => 'checklist_it', 'label' => 'Checklist IT', 'icone' => 'Clock', 'couleur_bg' => '#E3F2FD', 'couleur_texte' => '#0D47A1'],
            ['slug' => 'passation', 'label' => 'Passation', 'icone' => 'ArrowRight', 'couleur_bg' => '#F3E5F5', 'couleur_texte' => '#6A1B9A'],
            ['slug' => 'visite', 'label' => 'Visite', 'icone' => 'MapPin', 'couleur_bg' => '#E8F5E9', 'couleur_texte' => '#2E7D32'],
        ];

        $actionTypes = [];
        foreach ($actionTypesData as $at) {
            $actionTypes[$at['slug']] = ActionType::create($at);
        }

        // ── 5. Actions — all 13 types with full options ─────────
        $actionsData = [
            // ── ONBOARDING STANDARD ─────────────────────────────
            // document
            ['titre' => 'Compléter mon dossier administratif', 'type' => 'document', 'phase' => 'Avant le premier jour', 'delai' => 'J-30', 'oblig' => true, 'desc' => "Fournir tous les documents administratifs requis pour l'embauche", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'pieces' => ["Pièce d'identité / Passeport", "RIB / IBAN", "Attestation sécurité sociale", "Photo d'identité"],
             'options' => ['pieces' => ["Pièce d'identité / Passeport", "RIB / IBAN", "Attestation sécurité sociale", "Photo d'identité"], 'fichiersAcceptes' => 'PDF, Image (JPG, PNG)']],
            // formulaire
            ['titre' => 'Compléter les formulaires Suisse', 'type' => 'formulaire', 'phase' => 'Avant le premier jour', 'delai' => 'J-21', 'oblig' => true, 'desc' => 'Formulaires permis, déclaration IS, fiche identification', 'parcours' => 'Onboarding Standard', 'mode' => 'site', 'valeurs' => ['Genève', 'Lausanne'],
             'options' => ['champs' => [['label' => 'Numéro AVS', 'type' => 'texte'], ['label' => 'Date de naissance', 'type' => 'date'], ['label' => 'Nationalité', 'type' => 'texte'], ['label' => 'Adresse complète', 'type' => 'textarea'], ['label' => "Numéro de permis", 'type' => 'texte']]]],
            // formation
            ['titre' => 'Découvre le groupe Illizeo', 'type' => 'formation', 'phase' => 'Avant le premier jour', 'delai' => 'J-14', 'oblig' => true, 'desc' => 'Vidéo de présentation du groupe et de ses valeurs', 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'lien' => 'https://illizeo.com/onboard/decouverte', 'duree' => '15 min',
             'options' => ['support' => 'video']],
            ['titre' => 'A la rencontre de nos leaders !', 'type' => 'formation', 'phase' => 'Premier jour', 'delai' => 'J+0', 'oblig' => false, 'desc' => "Vidéos capsules des leaders de l'entreprise", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'lien' => 'https://illizeo.com/onboard/leaders', 'duree' => '20 min',
             'options' => ['support' => 'video']],
            ['titre' => 'Formation sécurité & RGPD', 'type' => 'formation', 'phase' => 'Première semaine', 'delai' => 'J+3', 'oblig' => true, 'desc' => 'Module e-learning obligatoire sur la sécurité informatique et le RGPD', 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'lien' => 'https://illizeo.com/elearning/rgpd', 'duree' => '45 min',
             'options' => ['support' => 'scorm']],
            // questionnaire
            ['titre' => "Questionnaire d'intégration J+7", 'type' => 'questionnaire', 'phase' => 'Première semaine', 'delai' => 'J+7', 'oblig' => true, 'desc' => "Feedback sur la première semaine d'intégration", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['questions' => [['question' => "Comment s'est passée votre première semaine ?", 'type' => 'libre'], ['question' => 'Avez-vous bien été accueilli(e) ?', 'type' => 'oui_non'], ['question' => "Notez votre intégration (1-10)", 'type' => 'note'], ['question' => 'Quel aspect améliorer ?', 'type' => 'libre']], 'scoreMinimum' => 0]],
            ['titre' => "Quiz culture d'entreprise", 'type' => 'questionnaire', 'phase' => '3 premiers mois', 'delai' => 'J+30', 'oblig' => false, 'desc' => 'Testez vos connaissances sur Illizeo après 1 mois', 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['questions' => [['question' => "En quelle année Illizeo a été fondée ?", 'type' => 'qcm'], ['question' => 'Combien de sites Illizeo possède ?', 'type' => 'qcm'], ['question' => 'Quel est le slogan Illizeo ?', 'type' => 'libre']], 'scoreMinimum' => 60]],
            // tache
            ['titre' => 'Personnaliser son espace de travail', 'type' => 'tache', 'phase' => 'Première semaine', 'delai' => 'J+1', 'oblig' => false, 'desc' => "Configurer son poste de travail et ses outils", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['sousTaches' => ["Configurer sa signature email", "Installer les logiciels métier", "Personnaliser son profil Teams/Slack", "Configurer le VPN", "Tester l'accès au drive partagé"]]],
            // signature
            ['titre' => 'Signer la charte informatique', 'type' => 'signature', 'phase' => 'Premier jour', 'delai' => 'J+0', 'oblig' => true, 'desc' => "Signature de la charte d'utilisation des outils IT", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['documentNom' => 'Charte informatique 2026', 'provider' => 'docusign', 'rappelAuto' => true, 'certifie' => false]],
            ['titre' => 'Signer le contrat de travail', 'type' => 'signature', 'phase' => 'Avant le premier jour', 'delai' => 'J-14', 'oblig' => true, 'desc' => "Signature électronique du contrat de travail", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['documentNom' => 'Contrat CDI', 'provider' => 'ugosign', 'rappelAuto' => true, 'certifie' => true]],
            // lecture
            ['titre' => 'Lire le règlement intérieur', 'type' => 'lecture', 'phase' => 'Avant le premier jour', 'delai' => 'J-7', 'oblig' => true, 'desc' => "Document PDF à lire obligatoirement avant l'arrivée", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['confirmationRequise' => true]],
            ['titre' => 'Lire la politique de confidentialité', 'type' => 'lecture', 'phase' => 'Premier jour', 'delai' => 'J+0', 'oblig' => true, 'desc' => "Politique de protection des données personnelles", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'lien' => 'https://illizeo.com/privacy',
             'options' => ['confirmationRequise' => true]],
            // rdv
            ['titre' => 'Visite des locaux', 'type' => 'rdv', 'phase' => 'Premier jour', 'delai' => 'J+0', 'oblig' => true, 'desc' => 'Visite guidée des bureaux et espaces communs', 'parcours' => 'Onboarding Standard', 'mode' => 'site', 'valeurs' => ['Genève'], 'duree' => '45 min',
             'options' => ['lieu' => 'Accueil — Bâtiment A', 'participants' => 'Buddy, Office Manager']],
            ['titre' => 'Planifier le point manager', 'type' => 'rdv', 'phase' => 'Première semaine', 'delai' => 'J+3', 'oblig' => true, 'desc' => 'Créneau de 30 min avec votre manager direct', 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '30 min',
             'options' => ['lieu' => 'Bureau du manager ou Teams', 'participants' => 'Manager direct']],
            ['titre' => 'Définir les objectifs 3 mois', 'type' => 'rdv', 'phase' => '3 premiers mois', 'delai' => 'J+30', 'oblig' => true, 'desc' => 'Fixer les objectifs des 3 premiers mois', 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '1h',
             'options' => ['lieu' => 'Salle de réunion', 'participants' => 'Manager, HRBP']],
            // message
            ['titre' => "Se présenter à l'équipe", 'type' => 'message', 'phase' => 'Première semaine', 'delai' => 'J+1', 'oblig' => false, 'desc' => 'Message de présentation pour briser la glace', 'parcours' => 'Onboarding Standard', 'mode' => 'groupe', 'valeurs' => ['Nouveaux arrivants Genève'],
             'options' => ['canal' => 'slack', 'destinataires' => 'Canal #nouveaux-arrivants', 'template' => "Bonjour à tous ! Je suis {{prenom}}, je rejoins l'équipe {{departement}} en tant que {{poste}}. Ravi(e) de faire partie de l'aventure Illizeo !"]],
            // entretien
            ['titre' => 'Point de suivi J+15', 'type' => 'entretien', 'phase' => 'Première semaine', 'delai' => 'J+15', 'oblig' => true, 'desc' => 'Entretien de suivi avec le manager après 2 semaines', 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '30 min',
             'options' => ['participants' => 'Manager direct', 'trame' => ["Comment vous sentez-vous dans l'équipe ?", "Avez-vous les outils nécessaires ?", "Y a-t-il des blocages ?", "Points positifs / axes d'amélioration"]]],
            ['titre' => "Rapport d'étonnement", 'type' => 'entretien', 'phase' => '3 premiers mois', 'delai' => 'J+60', 'oblig' => false, 'desc' => 'Recueillir les impressions du collaborateur après 2 mois', 'parcours' => 'Onboarding Standard', 'mode' => 'contrat', 'valeurs' => ['CDI'], 'duree' => '45 min',
             'options' => ['participants' => 'HRBP', 'trame' => ["Qu'est-ce qui vous a le plus surpris ?", "Qu'est-ce qui fonctionne bien ?", "Que changeriez-vous ?", "Recommanderiez-vous Illizeo ?"]]],
            // checklist_it
            ['titre' => 'Provisioning IT — Accès & matériel', 'type' => 'checklist_it', 'phase' => 'Avant le premier jour', 'delai' => 'J-7', 'oblig' => true, 'desc' => "Préparer tous les accès et matériel pour le nouveau collaborateur", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['items' => ["Créer le compte Active Directory", "Configurer la boîte email", "Attribuer une licence Microsoft 365", "Préparer le laptop (image standard)", "Créer le compte Slack/Teams", "Configurer le VPN", "Commander le badge d'accès", "Ajouter aux groupes de sécurité"], 'responsableIT' => 'Équipe IT Suisse']],
            // passation (utilise un faux contexte onboarding pour la démo)
            ['titre' => 'Récupérer les accès du prédécesseur', 'type' => 'passation', 'phase' => 'Premier jour', 'delai' => 'J+0', 'oblig' => false, 'desc' => "Récupérer les documents, accès et contacts du poste", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['elements' => ["Documentation du poste", "Accès aux projets en cours", "Liste des contacts clés", "Mots de passe partagés (coffre-fort)"], 'successeur' => 'N/A (prise de poste)']],
            // visite
            ['titre' => 'Rencontrer son buddy', 'type' => 'visite', 'phase' => 'Premier jour', 'delai' => 'J+0', 'oblig' => true, 'desc' => "Premier contact avec le parrain/marraine d'intégration", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '30 min',
             'options' => ['lieu' => 'Cafétéria / espace commun', 'guide' => 'Buddy assigné']],
            ['titre' => "Déjeuner d'équipe", 'type' => 'visite', 'phase' => 'Premier jour', 'delai' => 'J+0', 'oblig' => false, 'desc' => "Déjeuner informel avec l'équipe", 'parcours' => 'Onboarding Standard', 'mode' => 'groupe', 'valeurs' => ['Nouveaux arrivants Genève'], 'duree' => '1h',
             'options' => ['lieu' => 'Restaurant partenaire', 'guide' => 'Manager + Buddy']],

            // ── OFFBOARDING ─────────────────────────────────────
            ['titre' => 'Restitution matériel IT', 'type' => 'checklist_it', 'phase' => 'Annonce', 'delai' => 'J-7', 'oblig' => true, 'desc' => 'Restituer tout le matériel professionnel', 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['items' => ["Laptop", "Badge d'accès", "Téléphone professionnel", "Clés de bureau", "Carte de transport"], 'responsableIT' => 'IT Support']],
            ['titre' => 'Désactivation des accès', 'type' => 'checklist_it', 'phase' => 'Dernier jour', 'delai' => 'J+0', 'oblig' => true, 'desc' => 'Désactiver tous les comptes et accès', 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['items' => ["Email", "VPN", "Slack/Teams", "Accès drives", "Outils métier", "Badge physique"], 'responsableIT' => 'Admin IT']],
            ['titre' => 'Entretien de départ', 'type' => 'entretien', 'phase' => 'Transition', 'delai' => 'J-14', 'oblig' => true, 'desc' => 'Entretien confidentiel avec le HRBP', 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '45 min',
             'options' => ['participants' => 'HRBP', 'trame' => ["Raison du départ", "Satisfaction générale", "Relations avec le management", "Suggestions d'amélioration", "Recommanderiez-vous Illizeo ?"]]],
            ['titre' => 'Plan de passation', 'type' => 'passation', 'phase' => 'Transition', 'delai' => 'J-21', 'oblig' => true, 'desc' => 'Transférer connaissances, projets et contacts', 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['elements' => ["Projets en cours + état d'avancement", "Documentation technique", "Contacts clients/partenaires", "Accès et mots de passe (via coffre-fort)", "Procédures récurrentes"], 'successeur' => 'À définir par le manager']],
            ['titre' => 'Solde de tout compte', 'type' => 'signature', 'phase' => 'Dernier jour', 'delai' => 'J+0', 'oblig' => true, 'desc' => 'Signature des documents de fin de contrat', 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['documentNom' => 'Solde de tout compte', 'provider' => 'ugosign', 'rappelAuto' => false, 'certifie' => true]],
            ['titre' => 'Certificat de travail', 'type' => 'document', 'phase' => 'Post-départ', 'delai' => 'J+7', 'oblig' => true, 'desc' => 'Génération et remise du certificat de travail', 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['pieces' => ['Certificat de travail', 'Attestation Pôle Emploi'], 'fichiersAcceptes' => 'PDF']],
            ['titre' => "Communication départ", 'type' => 'message', 'phase' => 'Transition', 'delai' => 'J-7', 'oblig' => false, 'desc' => "Informer l'équipe du départ", 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['canal' => 'email', 'destinataires' => "Équipe + parties prenantes", 'template' => "Chers collègues, je vous informe que {{prenom}} quittera l'entreprise le {{date}}. Merci de faciliter la transition."]],
            ['titre' => 'Invitation réseau alumni', 'type' => 'message', 'phase' => 'Post-départ', 'delai' => 'J+14', 'oblig' => false, 'desc' => "Invitation au réseau alumni Illizeo", 'parcours' => 'Départ standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['canal' => 'email', 'destinataires' => 'Collaborateur sortant', 'template' => "Bonjour {{prenom}}, nous espérons que votre expérience chez Illizeo a été enrichissante. Rejoignez notre réseau alumni !"]],

            // ── CROSSBOARDING ───────────────────────────────────
            ['titre' => 'Avenant au contrat', 'type' => 'signature', 'phase' => 'Annonce mobilité', 'delai' => 'J-21', 'oblig' => true, 'desc' => "Signature de l'avenant de mobilité", 'parcours' => 'Mobilité interne standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['documentNom' => 'Avenant mobilité interne', 'provider' => 'docusign', 'rappelAuto' => true, 'certifie' => true]],
            ['titre' => 'Transfert des projets', 'type' => 'passation', 'phase' => 'Transition poste', 'delai' => 'J-14', 'oblig' => true, 'desc' => "Handover complet des projets", 'parcours' => 'Mobilité interne standard', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['elements' => ["Projets en cours", "Documentation", "Contacts clés", "Outils spécifiques"], 'successeur' => 'À définir']],
            ['titre' => 'Formation nouveau poste', 'type' => 'formation', 'phase' => 'Transition poste', 'delai' => 'J-7', 'oblig' => true, 'desc' => 'Formation aux outils du nouveau poste', 'parcours' => 'Mobilité interne standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '2h',
             'options' => ['support' => 'lien']],
            ['titre' => 'Rencontre nouvelle équipe', 'type' => 'visite', 'phase' => 'Intégration équipe', 'delai' => 'J+0', 'oblig' => true, 'desc' => 'Rencontre avec les membres de la nouvelle équipe', 'parcours' => 'Mobilité interne standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '1h',
             'options' => ['lieu' => 'Nouveau bureau / salle équipe', 'guide' => 'Nouveau manager']],
            ['titre' => 'Point J+30 nouveau manager', 'type' => 'entretien', 'phase' => 'Suivi J+30', 'delai' => 'J+30', 'oblig' => true, 'desc' => 'Bilan du premier mois', 'parcours' => 'Mobilité interne standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '45 min',
             'options' => ['participants' => 'Nouveau manager, HRBP', 'trame' => ["Adaptation au poste", "Relation avec la nouvelle équipe", "Objectifs atteints", "Besoins de formation"]]],

            // ── REBOARDING ──────────────────────────────────────
            ['titre' => 'Mise à jour accès IT', 'type' => 'checklist_it', 'phase' => 'Pré-retour J-14', 'delai' => 'J-7', 'oblig' => true, 'desc' => 'Réactivation des accès et vérification matériel', 'parcours' => 'Retour congé maternité/parental', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['items' => ["Réactiver le compte AD", "Vérifier email", "Réactiver VPN", "Vérifier le laptop", "Mettre à jour les logiciels"], 'responsableIT' => 'IT Support']],
            ['titre' => 'Point de reprise manager', 'type' => 'entretien', 'phase' => 'Jour de retour', 'delai' => 'J+0', 'oblig' => true, 'desc' => 'Entretien de réaccueil', 'parcours' => 'Retour congé maternité/parental', 'mode' => 'tous', 'valeurs' => [], 'duree' => '30 min',
             'options' => ['participants' => 'Manager direct', 'trame' => ["Bienvenue de retour", "Changements survenus", "Nouveaux objectifs", "Aménagements nécessaires"]]],
            ['titre' => 'Briefing changements', 'type' => 'formation', 'phase' => 'Jour de retour', 'delai' => 'J+0', 'oblig' => true, 'desc' => "Présentation des changements (équipe, projets, outils)", 'parcours' => 'Retour congé maternité/parental', 'mode' => 'tous', 'valeurs' => [], 'duree' => '1h',
             'options' => ['support' => 'lien']],
            ['titre' => 'Aménagement du poste', 'type' => 'tache', 'phase' => 'Réintégration', 'delai' => 'J+7', 'oblig' => false, 'desc' => "Vérifier si un aménagement est nécessaire", 'parcours' => 'Retour congé maternité/parental', 'mode' => 'tous', 'valeurs' => [],
             'options' => ['sousTaches' => ["Vérifier les horaires aménagés", "Adapter le poste ergonomique si besoin", "Mettre à jour les accès bâtiment", "Informer l'équipe du retour"]]],
        ];

        foreach ($actionsData as $a) {
            Action::create([
                'titre' => $a['titre'],
                'action_type_id' => $actionTypes[$a['type']]->id,
                'phase_id' => isset($phases[$a['phase']]) ? $phases[$a['phase']]->id : null,
                'parcours_id' => isset($parcours[$a['parcours']]) ? $parcours[$a['parcours']]->id : null,
                'delai_relatif' => $a['delai'],
                'obligatoire' => $a['oblig'],
                'description' => $a['desc'],
                'lien_externe' => $a['lien'] ?? null,
                'duree_estimee' => $a['duree'] ?? null,
                'pieces_requises' => $a['pieces'] ?? null,
                'assignation_mode' => $a['mode'],
                'assignation_valeurs' => !empty($a['valeurs']) ? $a['valeurs'] : null,
                'options' => $a['options'] ?? null,
            ]);
        }

        // ── 6. Collaborateurs ───────────────────────────────────
        $collabsData = [
            ['prenom' => 'Nadia', 'nom' => 'FERREIRA', 'email' => 'nadia.ferreira@illizeo.com', 'poste' => 'Chef de Projet', 'site' => 'Genève', 'departement' => 'B030-Switzerland', 'date_debut' => '2026-06-01', 'phase' => "Avant date d'arrivée", 'progression' => 15, 'status' => 'en_retard', 'docs_valides' => 1, 'docs_total' => 5, 'actions_completes' => 0, 'actions_total' => 7, 'initials' => 'NF', 'couleur' => '#C2185B', 'parcours' => 'Onboarding Standard'],
            ['prenom' => 'Antoine', 'nom' => 'MOREL', 'email' => 'antoine.morel@illizeo.com', 'poste' => 'Développeur Full Stack', 'site' => 'Paris', 'departement' => 'Tech-France', 'date_debut' => '2026-06-15', 'phase' => "Avant date d'arrivée", 'progression' => 45, 'status' => 'en_cours', 'docs_valides' => 3, 'docs_total' => 4, 'actions_completes' => 2, 'actions_total' => 5, 'initials' => 'AM', 'couleur' => '#1A73E8', 'parcours' => 'Onboarding Standard'],
            ['prenom' => 'Inès', 'nom' => 'CARPENTIER', 'email' => 'ines.carpentier@illizeo.com', 'poste' => 'UX Designer', 'site' => 'Lyon', 'departement' => 'Design-France', 'date_debut' => '2026-07-01', 'phase' => 'Annonce mobilité', 'progression' => 80, 'status' => 'en_cours', 'docs_valides' => 4, 'docs_total' => 4, 'actions_completes' => 4, 'actions_total' => 5, 'initials' => 'IC', 'couleur' => '#4CAF50', 'parcours' => 'Mobilité interne standard'],
            ['prenom' => 'Youssef', 'nom' => 'HADJ', 'email' => 'youssef.hadj@illizeo.com', 'poste' => 'Data Analyst', 'site' => 'Genève', 'departement' => 'Data-Switzerland', 'date_debut' => '2026-03-10', 'phase' => 'Première semaine', 'progression' => 100, 'status' => 'termine', 'docs_valides' => 5, 'docs_total' => 5, 'actions_completes' => 7, 'actions_total' => 7, 'initials' => 'YH', 'couleur' => '#7B5EA7', 'parcours' => 'Onboarding Standard'],
            ['prenom' => 'Clara', 'nom' => 'VOGEL', 'email' => 'clara.vogel@illizeo.com', 'poste' => 'Consultante', 'site' => 'Lausanne', 'departement' => 'Consulting-CH', 'date_debut' => '2026-06-20', 'phase' => "Annonce", 'progression' => 0, 'status' => 'en_retard', 'docs_valides' => 0, 'docs_total' => 6, 'actions_completes' => 0, 'actions_total' => 8, 'initials' => 'CV', 'couleur' => '#F9A825', 'parcours' => 'Départ standard'],
        ];

        $collabs = [];
        foreach ($collabsData as $c) {
            $parcoursNom = $c['parcours'];
            unset($c['parcours']);
            $c['parcours_id'] = $parcours[$parcoursNom]->id;
            $collabs[$c['prenom'] . ' ' . $c['nom']] = Collaborateur::create($c);
        }

        // ── 7. Groupes ──────────────────────────────────────────
        $groupesData = [
            ['nom' => 'Nouveaux arrivants Genève', 'description' => 'Tous les collaborateurs intégrant le site de Genève', 'couleur' => '#C2185B', 'critere_type' => 'site', 'critere_valeur' => 'Genève', 'membres' => ['Nadia FERREIRA', 'Youssef HADJ', 'Clara VOGEL']],
            ['nom' => 'Équipe Tech', 'description' => 'Développeurs, data analysts et IT', 'couleur' => '#1A73E8', 'critere_type' => 'departement', 'critere_valeur' => 'Tech', 'membres' => ['Antoine MOREL', 'Youssef HADJ']],
            ['nom' => 'CDI France & Suisse', 'description' => 'Tous les contrats CDI', 'couleur' => '#4CAF50', 'critere_type' => 'contrat', 'critere_valeur' => 'CDI', 'membres' => ['Nadia FERREIRA', 'Antoine MOREL', 'Inès CARPENTIER']],
            ['nom' => 'Managers Suisse', 'description' => 'Managers sur les sites suisses', 'couleur' => '#F9A825', 'critere_type' => null, 'critere_valeur' => null, 'membres' => []],
            ['nom' => 'Stagiaires & Alternants', 'description' => 'Contrats stage et alternance', 'couleur' => '#7B5EA7', 'critere_type' => 'contrat', 'critere_valeur' => 'Stage', 'membres' => []],
        ];

        foreach ($groupesData as $g) {
            $membres = $g['membres'];
            unset($g['membres']);
            $groupe = Groupe::create($g);

            foreach ($membres as $membreNom) {
                if (isset($collabs[$membreNom])) {
                    $groupe->collaborateurs()->attach($collabs[$membreNom]->id);
                }
            }
        }

        // ── 8. Document Categories & Documents ──────────────────
        $docCatsData = [
            ['slug' => 'complementaires', 'titre' => 'Documents administratifs complémentaires', 'pieces' => [
                ['nom' => 'IBAN/BIC', 'obligatoire' => true, 'type' => 'upload'],
                ['nom' => 'Certificats De Travail et Diplômes', 'obligatoire' => true, 'type' => 'upload'],
            ]],
            ['slug' => 'formulaires', 'titre' => 'Formulaires à remplir et à renvoyer', 'pieces' => [
                ['nom' => 'Formulaire permis résident Vaud', 'obligatoire' => false, 'type' => 'formulaire'],
                ['nom' => 'Formulaire frontalier Genève', 'obligatoire' => false, 'type' => 'formulaire'],
                ['nom' => 'Déclaration impôt Vaudois', 'obligatoire' => false, 'type' => 'formulaire'],
                ['nom' => 'Fiche identification', 'obligatoire' => true, 'type' => 'formulaire'],
            ]],
            ['slug' => 'suisse', 'titre' => 'Documents administratifs – Suisse', 'pieces' => [
                ['nom' => 'Pièce d\'identité / Passeport', 'obligatoire' => true, 'type' => 'upload'],
                ['nom' => 'Carte d\'assuré social', 'obligatoire' => false, 'type' => 'upload'],
                ['nom' => 'Permis de travail ou de résidence', 'obligatoire' => false, 'type' => 'upload'],
                ['nom' => 'Photo d\'identité', 'obligatoire' => true, 'type' => 'upload'],
            ]],
            ['slug' => 'supplementaires', 'titre' => 'Documents administratifs supplémentaires', 'pieces' => [
                ['nom' => 'Pièce justificative 1', 'obligatoire' => false, 'type' => 'upload'],
                ['nom' => 'Pièce justificative 2', 'obligatoire' => false, 'type' => 'upload'],
                ['nom' => 'Pièce justificative 3', 'obligatoire' => false, 'type' => 'upload'],
            ]],
        ];

        foreach ($docCatsData as $dc) {
            $pieces = $dc['pieces'];
            unset($dc['pieces']);
            $cat = DocumentCategorie::create($dc);

            foreach ($pieces as $piece) {
                Document::create([
                    'nom' => $piece['nom'],
                    'obligatoire' => $piece['obligatoire'],
                    'type' => $piece['type'],
                    'categorie_id' => $cat->id,
                ]);
            }
        }

        // ── 9. Contrats ─────────────────────────────────────────
        $contratsData = [
            ['nom' => 'CDI — Droit Suisse', 'type' => 'CDI', 'juridiction' => 'Suisse', 'variables' => 18, 'derniere_maj' => '2026-02-15', 'actif' => true, 'fichier' => 'CDI_Suisse_v3.docx'],
            ['nom' => 'CDI — Droit Français', 'type' => 'CDI', 'juridiction' => 'France', 'variables' => 22, 'derniere_maj' => '2026-01-10', 'actif' => true, 'fichier' => 'CDI_France_v2.docx'],
            ['nom' => 'CDD — Droit Suisse', 'type' => 'CDD', 'juridiction' => 'Suisse', 'variables' => 20, 'derniere_maj' => '2026-02-15', 'actif' => true, 'fichier' => 'CDD_Suisse_v1.docx'],
            ['nom' => 'Convention de stage', 'type' => 'Stage', 'juridiction' => 'France', 'variables' => 15, 'derniere_maj' => '2026-03-05', 'actif' => true, 'fichier' => 'Convention_Stage_v4.docx'],
            ['nom' => "Contrat d'alternance", 'type' => 'Alternance', 'juridiction' => 'France', 'variables' => 16, 'derniere_maj' => '2026-01-12', 'actif' => false, 'fichier' => 'Alternance_v1.docx'],
            ['nom' => 'Avenant de mobilité', 'type' => 'Avenant', 'juridiction' => 'Multi', 'variables' => 12, 'derniere_maj' => '2026-02-20', 'actif' => true, 'fichier' => 'Avenant_Mobilite_v2.docx'],
        ];

        foreach ($contratsData as $c) {
            Contrat::create($c);
        }

        // ── 9b. Documents à signer ──────────────────────────────
        $signatureDocsData = [
            ['titre' => 'Règlement intérieur', 'description' => "Le collaborateur doit lire et accepter le règlement intérieur de l'entreprise.", 'type' => 'lecture', 'obligatoire' => true, 'actif' => true],
            ['titre' => "Charte informatique", 'description' => "Charte d'utilisation des outils informatiques, messagerie et accès réseau.", 'type' => 'signature', 'obligatoire' => true, 'actif' => true],
            ['titre' => "Droit à l'image", 'description' => "Autorisation d'utilisation de l'image du collaborateur pour la communication interne et externe.", 'type' => 'signature', 'obligatoire' => false, 'actif' => true],
            ['titre' => 'Accord de confidentialité (NDA)', 'description' => "Engagement de non-divulgation des informations confidentielles de l'entreprise.", 'type' => 'signature', 'obligatoire' => true, 'actif' => true],
            ['titre' => 'Politique de protection des données (RGPD)', 'description' => "Politique de traitement des données personnelles conformément au RGPD.", 'type' => 'lecture', 'obligatoire' => true, 'actif' => true],
            ['titre' => 'Charte éthique & RSE', 'description' => "Engagements éthiques, anti-corruption et responsabilité sociétale de l'entreprise.", 'type' => 'lecture', 'obligatoire' => false, 'actif' => true],
            ['titre' => 'Avenant télétravail', 'description' => "Conditions et modalités du télétravail, jours autorisés et équipement fourni.", 'type' => 'signature', 'obligatoire' => false, 'actif' => true],
            ['titre' => 'Politique santé & sécurité', 'description' => "Règles de sécurité au travail, procédures d'évacuation et contacts d'urgence.", 'type' => 'lecture', 'obligatoire' => true, 'actif' => true],
        ];

        foreach ($signatureDocsData as $sd) {
            SignatureDocument::create($sd);
        }

        // ── 9b. Link signature actions to signature documents ──
        $sigLinkMap = [
            'Signer la charte informatique' => 'Charte informatique',
            'Signer le contrat de travail' => 'Accord de confidentialité (NDA)',
            'Solde de tout compte' => 'Accord de confidentialité (NDA)',
            'Avenant au contrat' => 'Avenant télétravail',
            'Lire le règlement intérieur' => 'Règlement intérieur',
            'Lire la politique de confidentialité' => 'Politique de protection des données (RGPD)',
        ];
        foreach ($sigLinkMap as $actionTitle => $docTitle) {
            $doc = SignatureDocument::where('titre', $docTitle)->first();
            $action = Action::where('titre', $actionTitle)->first();
            if ($doc && $action) {
                $opts = $action->options ?? [];
                $opts['signature_document_id'] = $doc->id;
                $action->update(['options' => $opts]);
            }
        }

        // ── 10. Workflows ───────────────────────────────────────
        $workflowsData = [
            ['nom' => "Validation pièce d'identité", 'declencheur' => 'Document soumis', 'action' => 'Envoyer pour validation au Manager', 'destinataire' => 'Manager direct', 'actif' => true],
            ['nom' => 'Relance documents en retard', 'declencheur' => 'J-7 avant date limite', 'action' => 'Envoyer email de relance', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Notification nouveau collaborateur', 'declencheur' => 'Parcours créé', 'action' => "Notifier l'équipe RH", 'destinataire' => 'Équipe RH', 'actif' => true],
            ['nom' => 'Validation dossier complet', 'declencheur' => 'Tous documents validés', 'action' => 'Envoyer confirmation au collaborateur', 'destinataire' => 'Collaborateur', 'actif' => false],
            ['nom' => 'Approbation formulaires Suisse', 'declencheur' => 'Formulaire soumis', 'action' => 'Envoyer pour approbation Admin RH', 'destinataire' => 'Admin RH Suisse', 'actif' => true],
            ['nom' => 'Alerte collaborateur en retard', 'declencheur' => 'Collaborateur en retard', 'action' => 'Envoyer email de relance', 'destinataire' => 'Manager direct', 'actif' => true],
            ['nom' => 'Message bienvenue IllizeoBot', 'declencheur' => 'Nouveau collaborateur', 'action' => 'Envoyer un message IllizeoBot', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Notification Teams — Nouveau', 'declencheur' => 'Nouveau collaborateur', 'action' => 'Envoyer via Teams', 'destinataire' => 'Équipe RH', 'actif' => true],
            ['nom' => 'Badge parcours terminé', 'declencheur' => 'Parcours complété à 100%', 'action' => 'Attribuer un badge', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => "Évaluation fin période d'essai", 'declencheur' => "Période d'essai terminée", 'action' => 'Envoyer pour validation au Manager', 'destinataire' => 'Manager direct', 'actif' => true],
            ['nom' => 'Relance document refusé', 'declencheur' => 'Document refusé', 'action' => 'Envoyer email de relance', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Alerte NPS négatif', 'declencheur' => 'Questionnaire NPS soumis', 'action' => "Notifier l'équipe RH", 'destinataire' => 'Équipe RH', 'actif' => true],
            ['nom' => 'Récompense cooptation', 'declencheur' => 'Cooptation validée', 'action' => "Notifier l'équipe RH", 'destinataire' => 'Équipe RH', 'actif' => false],
            ['nom' => 'Félicitations anniversaire', 'declencheur' => "Anniversaire d'embauche", 'action' => 'Envoyer un message IllizeoBot', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Désactivation accès offboarding', 'declencheur' => 'Fin de parcours offboarding', 'action' => "Notifier l'équipe RH", 'destinataire' => 'Équipe RH', 'actif' => true],
        ];

        foreach ($workflowsData as $w) {
            Workflow::create($w);
        }

        // ── 11. Email Templates ─────────────────────────────────
        $emailTemplatesData = [
            ['nom' => 'Invitation onboarding', 'sujet' => "Bienvenue chez Illizeo – Ton parcours d'intégration", 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{date_debut}}', '{{site}}'], 'actif' => true, 'contenu' => "<h2>Bienvenue {{prenom}} !</h2><p>Nous sommes ravis de t'accueillir. Ton parcours d'intégration débute le <b>{{date_debut}}</b> sur le site de <b>{{site}}</b>.</p><p>Connecte-toi à ton espace pour découvrir tes premières actions.</p>"],
            ['nom' => 'Relance documents', 'sujet' => 'Rappel : documents à compléter', 'declencheur' => 'J-7 avant deadline documents', 'variables' => ['{{prenom}}', '{{nb_docs_manquants}}', '{{date_limite}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Il vous reste <b>{{nb_docs_manquants}} document(s)</b> à fournir avant le <b>{{date_limite}}</b>.</p><p>Connectez-vous à votre espace pour les compléter.</p>"],
            ['nom' => 'Confirmation dossier complet', 'sujet' => 'Ton dossier est complet !', 'declencheur' => 'Tous documents validés', 'variables' => ['{{prenom}}', '{{date_debut}}'], 'actif' => true, 'contenu' => "<p>Bravo {{prenom}} ! 🎉</p><p>Tous vos documents ont été validés. Votre dossier administratif est complet pour votre arrivée le <b>{{date_debut}}</b>.</p>"],
            ['nom' => 'Bienvenue premier jour', 'sujet' => 'C\'est le grand jour {{prenom}} !', 'declencheur' => 'J+0', 'variables' => ['{{prenom}}', '{{site}}', '{{adresse}}', '{{manager}}'], 'actif' => true, 'contenu' => "<h2>Bienvenue {{prenom}} ! 🚀</h2><p>C'est le grand jour ! Rendez-vous à <b>{{adresse}}</b> (site {{site}}).</p><p>Votre manager <b>{{manager}}</b> vous accueillera. Bonne première journée !</p>"],
            ['nom' => 'Fin de parcours', 'sujet' => 'Félicitations – Parcours terminé', 'declencheur' => 'Parcours complété à 100%', 'variables' => ['{{prenom}}', '{{parcours_nom}}'], 'actif' => true, 'contenu' => "<p>Félicitations {{prenom}} ! 🏆</p><p>Vous avez complété l'intégralité de votre parcours <b>{{parcours_nom}}</b>. Merci pour votre implication !</p>"],
            ['nom' => 'Rappel action en retard', 'sujet' => 'Action en retard : {{action_nom}}', 'declencheur' => 'Action non complétée après deadline', 'variables' => ['{{prenom}}', '{{action_nom}}', '{{deadline}}', '{{parcours_nom}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>L'action <b>{{action_nom}}</b> de votre parcours <b>{{parcours_nom}}</b> était prévue pour le <b>{{deadline}}</b> et n'a pas encore été complétée.</p><p>Merci de vous en occuper dès que possible.</p>"],
            ['nom' => 'Notification manager – Nouvel arrivant', 'sujet' => 'Nouvel arrivant dans votre équipe : {{collab_prenom}} {{collab_nom}}', 'declencheur' => 'Création du collaborateur', 'variables' => ['{{manager_prenom}}', '{{collab_prenom}}', '{{collab_nom}}', '{{poste}}', '{{date_debut}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{manager_prenom}},</p><p><b>{{collab_prenom}} {{collab_nom}}</b> rejoindra votre équipe au poste de <b>{{poste}}</b> le <b>{{date_debut}}</b>.</p><p>Connectez-vous pour suivre son parcours d'intégration.</p>"],
            ['nom' => 'Enquête de satisfaction', 'sujet' => 'Votre avis compte : enquête de satisfaction', 'declencheur' => 'Envoi NPS', 'variables' => ['{{prenom}}', '{{survey_nom}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Nous aimerions recueillir votre retour sur votre expérience d'intégration via l'enquête <b>{{survey_nom}}</b>.</p><p>Cela ne prend que 2 minutes. Merci !</p>"],
            ['nom' => 'Badge obtenu', 'sujet' => 'Vous avez obtenu un nouveau badge ! 🏅', 'declencheur' => 'Attribution de badge', 'variables' => ['{{prenom}}', '{{badge_nom}}'], 'actif' => true, 'contenu' => "<p>Bravo {{prenom}} ! 🏅</p><p>Vous avez obtenu le badge <b>{{badge_nom}}</b>. Retrouvez tous vos badges dans votre tableau de bord.</p>"],
            ['nom' => 'Relance signature document', 'sujet' => 'Document en attente de signature', 'declencheur' => 'J+3 après envoi document', 'variables' => ['{{prenom}}', '{{document_nom}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Le document <b>{{document_nom}}</b> est en attente de votre signature. Merci de le consulter depuis votre espace.</p>"],
            ['nom' => 'Rappel RH – Actions en retard', 'sujet' => 'Rapport : {{nb_retards}} action(s) en retard', 'declencheur' => 'Hebdomadaire (lundi)', 'variables' => ['{{rh_prenom}}', '{{nb_retards}}', '{{nb_collaborateurs}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{rh_prenom}},</p><p>Cette semaine, <b>{{nb_retards}} action(s)</b> sont en retard pour <b>{{nb_collaborateurs}} collaborateur(s)</b>.</p><p>Consultez le suivi pour agir.</p>"],
            ['nom' => 'Offboarding – Départ prévu', 'sujet' => 'Départ prévu : {{collab_prenom}} {{collab_nom}}', 'declencheur' => 'J-30 avant date de fin', 'variables' => ['{{rh_prenom}}', '{{collab_prenom}}', '{{collab_nom}}', '{{date_fin}}', '{{poste}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{rh_prenom}},</p><p><b>{{collab_prenom}} {{collab_nom}}</b> ({{poste}}) quittera l'entreprise le <b>{{date_fin}}</b>.</p><p>Le parcours d'offboarding a été initié. Vérifiez les actions à compléter.</p>"],
            ['nom' => 'Document refusé', 'sujet' => 'Document refusé — Action requise', 'declencheur' => 'Document refusé', 'variables' => ['{{prenom}}', '{{document_nom}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Le document <b>{{document_nom}}</b> a été refusé. Merci de le corriger et de le soumettre à nouveau depuis votre espace.</p>"],
            ['nom' => 'Action assignée', 'sujet' => 'Nouvelle tâche : {{action_nom}}', 'declencheur' => 'Action assignée', 'variables' => ['{{prenom}}', '{{action_nom}}', '{{date_limite}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Une nouvelle tâche vous a été assignée : <b>{{action_nom}}</b>.</p><p>Date limite : <b>{{date_limite}}</b>. Connectez-vous pour la consulter.</p>"],
            ['nom' => "Fin période d'essai", 'sujet' => "Période d'essai — Évaluation de {{prenom}}", 'declencheur' => "Période d'essai terminée", 'variables' => ['{{manager}}', '{{prenom}}', '{{date_fin_essai}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{manager}},</p><p>La période d'essai de <b>{{prenom}}</b> se termine le <b>{{date_fin_essai}}</b>.</p><p>Merci de compléter l'évaluation depuis votre espace manager.</p>"],
            ['nom' => "Anniversaire d'embauche", 'sujet' => 'Joyeux anniversaire professionnel {{prenom}} !', 'declencheur' => "Anniversaire d'embauche", 'variables' => ['{{prenom}}', '{{annees}}', '{{date_debut}}'], 'actif' => true, 'contenu' => "<p>Joyeux anniversaire professionnel {{prenom}} ! 🎂</p><p>Cela fait déjà <b>{{annees}} an(s)</b> que vous avez rejoint l'équipe le <b>{{date_debut}}</b>. Merci pour votre engagement !</p>"],
            // Onboarding complémentaires
            ['nom' => 'Rappel pré-arrivée J-3', 'sujet' => 'Plus que 3 jours avant votre premier jour {{prenom}} !', 'declencheur' => 'J-3', 'variables' => ['{{prenom}}', '{{site}}', '{{adresse}}', '{{manager}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Votre arrivée est prévue dans <b>3 jours</b> ! 🎯</p><p>Rendez-vous au site <b>{{site}}</b> ({{adresse}}). Votre manager <b>{{manager}}</b> vous accueillera.</p><p>D'ici là, pensez à vérifier que tous vos documents sont bien soumis.</p>"],
            ['nom' => 'Feedback buddy / parrain', 'sujet' => "Comment se passe l'intégration de {{collab_nom}} ?", 'declencheur' => 'J+14', 'variables' => ['{{prenom}}', '{{collab_nom}}', '{{parcours_nom}}', '{{lien}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Vous êtes le buddy/parrain de <b>{{collab_nom}}</b> dans le cadre du parcours <b>{{parcours_nom}}</b>.</p><p>Cela fait 2 semaines : comment se passe l'intégration ? Votre retour nous aide à améliorer l'expérience.</p>"],
            // Document validé
            ['nom' => 'Document validé', 'sujet' => 'Votre document a été validé ✓', 'declencheur' => 'Document validé', 'variables' => ['{{prenom}}', '{{document_nom}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Votre document <b>{{document_nom}}</b> a été vérifié et validé avec succès. ✓</p>"],
            // Signatures
            ['nom' => 'Signature contrat', 'sujet' => 'Votre contrat est prêt à signer', 'declencheur' => 'Signature requise', 'variables' => ['{{prenom}}', '{{document_nom}}', '{{lien}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Le document <b>{{document_nom}}</b> est prêt pour votre signature électronique.</p><p>Cliquez ci-dessous pour le consulter et le signer.</p>"],
            // Crossboarding / Reboarding
            ['nom' => 'Mobilité interne — Début', 'sujet' => 'Votre transition vers {{poste}} commence !', 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{poste}}', '{{site}}', '{{manager}}', '{{parcours_nom}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Votre mobilité interne vers le poste de <b>{{poste}}</b> (site {{site}}) commence ! 🚀</p><p>Votre nouveau manager <b>{{manager}}</b> sera votre interlocuteur principal. Le parcours <b>{{parcours_nom}}</b> vous guidera dans cette transition.</p>"],
            ['nom' => 'Retour de congé — Bienvenue', 'sujet' => 'Content de vous retrouver {{prenom}} !', 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{site}}', '{{manager}}', '{{parcours_nom}}'], 'actif' => true, 'contenu' => "<p>Bienvenue de retour {{prenom}} ! 🤝</p><p>Nous sommes ravis de vous retrouver. Le parcours <b>{{parcours_nom}}</b> vous aidera à reprendre vos marques en douceur.</p><p>N'hésitez pas à contacter <b>{{manager}}</b> pour toute question.</p>"],
            // Communication
            ['nom' => 'Nouveau message reçu', 'sujet' => 'Vous avez un nouveau message de {{collab_nom}}', 'declencheur' => 'Nouveau message', 'variables' => ['{{prenom}}', '{{collab_nom}}', '{{lien}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p><b>{{collab_nom}}</b> vous a envoyé un message sur Illizeo.</p><p>Connectez-vous pour le lire et y répondre.</p>"],
            ['nom' => 'Résumé hebdomadaire', 'sujet' => "Votre semaine d'intégration — {{prenom}}", 'declencheur' => 'Hebdomadaire (lundi)', 'variables' => ['{{prenom}}', '{{nb_docs_manquants}}', '{{parcours_nom}}', '{{lien}}'], 'actif' => true, 'contenu' => "<p>Bonjour {{prenom}},</p><p>Voici votre résumé de la semaine pour le parcours <b>{{parcours_nom}}</b> :</p><ul><li>Documents manquants : <b>{{nb_docs_manquants}}</b></li></ul><p>Connectez-vous pour consulter vos prochaines étapes.</p>"],
        ];

        foreach ($emailTemplatesData as $et) {
            EmailTemplate::create($et);
        }

        // ── 12. Notifications Config ────────────────────────────
        $notifications = [
            'Anniversaire', 'Changement de rôle avant la date de début du parcours',
            'Changement de rôle sur un parcours en cours', 'Délégation créée',
            'Delegation Deleted', 'Delegation Ended', 'Délégation modifiée',
            'La délégation a commencé', 'Fin de contrat', "Fin de la période d'essai",
            "Fin de la période d'essai renouvelée", 'Gazette',
            "Invitation d'un utilisateur standard",
            'Relancer un parcours en retard pour le collaborateur',
            'Relancer les participants à un parcours en retard',
            'Les arrivées de la semaine', 'Une nouvelle recrue arrive',
            'Nouveau questionnaire disponible', 'Nouvelle tâche disponible',
            'Relance des invitations', 'Pièce administrative à signer disponible',
            'Catégorie de pièces administratives complétée',
            'Catégorie de pièce administrative refusée',
            'Catégorie de pièce administrative à valider',
            'Pièce administrative complétée', 'La ressource a été mise à jour',
            'Pièces administratives signées', 'Questionnaire complété',
        ];

        foreach ($notifications as $notif) {
            NotificationConfig::create([
                'nom' => $notif,
                'canal' => 'email',
                'actif' => true,
                'categorie' => 'general',
            ]);
        }

        // Additional categorized notifications
        $categorizedNotifs = [
            ['nom' => 'Nouveau message reçu', 'categorie' => 'communication'],
            ['nom' => 'Document validé', 'categorie' => 'document'],
            ['nom' => 'Badge obtenu', 'categorie' => 'gamification'],
            ['nom' => 'Cooptation — Statut mis à jour', 'categorie' => 'cooptation'],
            ['nom' => 'Parcours terminé', 'categorie' => 'parcours'],
            ['nom' => 'Signature de contrat requise', 'categorie' => 'signature'],
            ['nom' => 'Rappel pré-arrivée J-3', 'categorie' => 'onboarding'],
            ['nom' => 'Feedback buddy / parrain demandé', 'categorie' => 'onboarding'],
            ['nom' => 'Mobilité interne — Début de parcours', 'categorie' => 'crossboarding'],
            ['nom' => 'Retour de congé — Parcours initié', 'categorie' => 'reboarding'],
            ['nom' => 'Résumé hebdomadaire collaborateur', 'categorie' => 'general'],
            ['nom' => 'NPS — Nouvelle enquête disponible', 'categorie' => 'nps'],
        ];
        foreach ($categorizedNotifs as $cn) {
            NotificationConfig::create([
                'nom' => $cn['nom'],
                'canal' => 'email',
                'actif' => true,
                'categorie' => $cn['categorie'],
            ]);
        }

        // Resource notification
        NotificationConfig::create([
            'nom' => 'Événements',
            'canal' => 'email',
            'actif' => true,
            'categorie' => 'ressource',
        ]);

        // ── 13. Intégrations ────────────────────────────────
        $integrations = [
            // Signature
            ['provider' => 'docusign', 'categorie' => 'signature', 'nom' => 'DocuSign', 'config' => ['integration_key' => '', 'secret_key' => '', 'user_id' => '', 'api_account_id' => '', 'base_uri' => 'https://eu.docusign.net', 'environment' => 'sandbox', 'rsa_private_key' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'ugosign', 'categorie' => 'signature', 'nom' => 'UgoSign', 'config' => ['api_key' => '', 'api_secret' => '', 'environment' => 'sandbox'], 'actif' => false, 'connecte' => false],
            ['provider' => 'native', 'categorie' => 'signature', 'nom' => 'Signature in-app', 'config' => [], 'actif' => true, 'connecte' => true],
            // Communication
            ['provider' => 'teams', 'categorie' => 'communication', 'nom' => 'Microsoft Teams', 'config' => ['webhook_url' => '', 'mode' => 'webhook'], 'actif' => false, 'connecte' => false],
            ['provider' => 'slack', 'categorie' => 'communication', 'nom' => 'Slack', 'config' => ['bot_token' => '', 'webhook_url' => ''], 'actif' => false, 'connecte' => false],
            // Identity & SSO
            ['provider' => 'entra_id', 'categorie' => 'identity', 'nom' => 'Microsoft Entra ID', 'config' => ['tenant_id' => '', 'client_id' => '', 'client_secret' => ''], 'actif' => false, 'connecte' => false],
            // ATS
            ['provider' => 'smartrecruiters', 'categorie' => 'ats', 'nom' => 'SmartRecruiters', 'config' => ['api_key' => '', 'company_id' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'teamtailor', 'categorie' => 'ats', 'nom' => 'Teamtailor', 'config' => ['api_key' => ''], 'actif' => false, 'connecte' => false],
            // SIRH
            ['provider' => 'sap', 'categorie' => 'sirh', 'nom' => 'SAP SuccessFactors', 'config' => ['base_url' => '', 'company_id' => '', 'username' => '', 'password' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'personio', 'categorie' => 'sirh', 'nom' => 'Personio', 'config' => ['client_id' => '', 'client_secret' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'lucca', 'categorie' => 'sirh', 'nom' => 'Lucca', 'config' => ['subdomain' => '', 'api_key' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'bamboohr', 'categorie' => 'sirh', 'nom' => 'BambooHR', 'config' => ['company_domain' => '', 'api_key' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'workday', 'categorie' => 'sirh', 'nom' => 'Workday HCM', 'config' => ['host' => '', 'tenant' => '', 'client_id' => '', 'client_secret' => '', 'refresh_token' => ''], 'actif' => false, 'connecte' => false],
        ];

        foreach ($integrations as $i) {
            Integration::create($i);
        }

        // ── 14. Collaborateur field config ──────────────────
        $fieldConfigs = [
            // Personal
            ['field_key' => 'civilite', 'label' => 'Civilité', 'label_en' => 'Salutation', 'section' => 'personal', 'field_type' => 'list', 'list_values' => ['M.', 'Mme'], 'actif' => true, 'obligatoire' => false, 'ordre' => 1],
            ['field_key' => 'date_naissance', 'label' => 'Date de naissance', 'label_en' => 'Date of birth', 'section' => 'personal', 'field_type' => 'date', 'actif' => true, 'obligatoire' => false, 'ordre' => 2],
            ['field_key' => 'nationalite', 'label' => 'Nationalité', 'label_en' => 'Nationality', 'section' => 'personal', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 3],
            ['field_key' => 'numero_avs', 'label' => 'Numéro AVS / Sécurité sociale', 'label_en' => 'Social security number', 'section' => 'personal', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 4],
            ['field_key' => 'telephone', 'label' => 'Téléphone', 'label_en' => 'Phone', 'section' => 'personal', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 5],
            ['field_key' => 'adresse', 'label' => 'Adresse', 'label_en' => 'Address', 'section' => 'personal', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 6],
            ['field_key' => 'ville', 'label' => 'Ville', 'label_en' => 'City', 'section' => 'personal', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 7],
            ['field_key' => 'code_postal', 'label' => 'Code postal', 'label_en' => 'Postal code', 'section' => 'personal', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 8],
            ['field_key' => 'pays', 'label' => 'Pays', 'label_en' => 'Country', 'section' => 'personal', 'field_type' => 'list', 'list_values' => ['Suisse', 'France', 'Allemagne', 'Italie', 'Autre'], 'actif' => true, 'obligatoire' => false, 'ordre' => 9],
            ['field_key' => 'iban', 'label' => 'IBAN', 'label_en' => 'IBAN', 'section' => 'personal', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 10],
            // Contract
            ['field_key' => 'type_contrat', 'label' => 'Type de contrat', 'label_en' => 'Contract type', 'section' => 'contract', 'field_type' => 'list', 'list_values' => ['CDI', 'CDD', 'Stage', 'Alternance', 'Freelance'], 'actif' => true, 'obligatoire' => true, 'ordre' => 1],
            ['field_key' => 'salaire_brut', 'label' => 'Salaire brut annuel', 'label_en' => 'Annual gross salary', 'section' => 'contract', 'field_type' => 'number', 'actif' => true, 'obligatoire' => false, 'ordre' => 2],
            ['field_key' => 'devise', 'label' => 'Devise', 'label_en' => 'Currency', 'section' => 'contract', 'field_type' => 'list', 'list_values' => ['CHF', 'EUR', 'USD', 'GBP'], 'actif' => true, 'obligatoire' => false, 'ordre' => 3],
            ['field_key' => 'taux_activite', 'label' => "Taux d'activité (%)", 'label_en' => 'Work rate (%)', 'section' => 'contract', 'field_type' => 'number', 'actif' => true, 'obligatoire' => false, 'ordre' => 4],
            ['field_key' => 'periode_essai', 'label' => "Période d'essai", 'label_en' => 'Probation period', 'section' => 'contract', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 5],
            ['field_key' => 'date_fin_essai', 'label' => "Date de fin de période d'essai", 'label_en' => 'Probation end date', 'section' => 'contract', 'field_type' => 'date', 'actif' => false, 'obligatoire' => false, 'ordre' => 6],
            ['field_key' => 'convention_collective', 'label' => 'Convention collective', 'label_en' => 'Collective agreement', 'section' => 'contract', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 7],
            ['field_key' => 'duree_contrat', 'label' => 'Durée du contrat (CDD)', 'label_en' => 'Contract duration', 'section' => 'contract', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 8],
            // Org
            ['field_key' => 'matricule', 'label' => 'Matricule employé', 'label_en' => 'Employee ID', 'section' => 'org', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 1],
            ['field_key' => 'manager_nom', 'label' => 'Manager', 'label_en' => 'Manager', 'section' => 'org', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 2],
            ['field_key' => 'centre_cout', 'label' => 'Centre de coût', 'label_en' => 'Cost center', 'section' => 'org', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 3],
            ['field_key' => 'entite_juridique', 'label' => 'Entité juridique', 'label_en' => 'Legal entity', 'section' => 'org', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 4],
            ['field_key' => 'categorie_pro', 'label' => 'Catégorie professionnelle', 'label_en' => 'Job category', 'section' => 'org', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 5],
            ['field_key' => 'niveau_hierarchique', 'label' => 'Niveau hiérarchique', 'label_en' => 'Job level', 'section' => 'org', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 6],
            ['field_key' => 'recruteur', 'label' => 'Recruteur', 'label_en' => 'Recruiter', 'section' => 'org', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 7],
        ];
        foreach ($fieldConfigs as $fc) {
            CollaborateurFieldConfig::create($fc);
        }

        // ── 15. Company page blocks ──────────────────────────
        $blocks = [
            ['type' => 'hero', 'titre' => 'Bienvenue chez Illizeo', 'contenu' => "Nous sommes ravis de vous accueillir dans l'équipe. Découvrez notre entreprise, notre mission et nos valeurs.", 'data' => ['subtitle' => 'Votre aventure commence ici', 'image_url' => ''], 'ordre' => 1, 'translations' => ['titre' => ['en' => 'Welcome to Illizeo', 'de' => 'Willkommen bei Illizeo'], 'contenu' => ['en' => 'We are delighted to welcome you to the team. Discover our company, our mission and our values.', 'de' => 'Wir freuen uns, Sie im Team begrüßen zu dürfen.']]],
            ['type' => 'text', 'titre' => 'À propos de nous', 'contenu' => "Illizeo est un groupe international de conseil et d'expertises technologiques qui accélère la transformation de ses clients par les leviers de l'innovation, la technologie et la data. Présent sur 5 continents, dans 18 pays, le Groupe, certifié Great Place To Work, comptera plus de 7200 collaborateurs fin 2024.", 'data' => ['icon' => 'building'], 'ordre' => 2, 'translations' => ['titre' => ['en' => 'About us', 'de' => 'Über uns'], 'contenu' => ['en' => "Illizeo is an international consulting and technology expertise group that accelerates its clients' transformation through innovation, technology and data. Present on 5 continents, in 18 countries, the Group, certified Great Place To Work, will have more than 7,200 employees by the end of 2024.", 'de' => 'Illizeo ist eine internationale Beratungs- und Technologiegruppe.']]],
            ['type' => 'mission', 'titre' => 'Notre mission', 'contenu' => "Accélérer votre transformation par les leviers de la technologie, de la data et de l'innovation.", 'data' => ['number' => '01'], 'ordre' => 3, 'translations' => ['titre' => ['en' => 'Our mission', 'de' => 'Unsere Mission'], 'contenu' => ['en' => 'Accelerating your transformation through technology, data and innovation.', 'de' => 'Ihre Transformation durch Technologie, Daten und Innovation beschleunigen.']]],
            ['type' => 'text', 'titre' => 'Positive Innovation', 'contenu' => "La « Positive Innovation » c'est la trajectoire que propose Illizeo à ses clients pour garantir un impact positif dans la conduite de leurs projets et pour accélérer leur transformation.", 'data' => ['icon' => 'sparkles'], 'ordre' => 4, 'translations' => ['titre' => ['en' => 'Positive Innovation'], 'contenu' => ['en' => '"Positive Innovation" is the trajectory that Illizeo offers its clients to ensure a positive impact in their projects and accelerate their transformation.']]],
            ['type' => 'stats', 'titre' => 'Un groupe formidable où travailler', 'contenu' => 'Nous sommes une formidable équipe', 'data' => ['badge' => 'Great Place to Work depuis 2014', 'items' => [
                ['value' => '83%', 'label' => "de nos employés disent qu'Illizeo est un endroit formidable où travailler"],
                ['value' => '85%', 'label' => 'de nos employés sont prêts à se surpasser pour que le travail soit fait'],
                ['value' => '80%', 'label' => "confirme que les employés d'Illizeo cherchent à innover"],
            ]], 'ordre' => 5, 'translations' => ['titre' => ['en' => 'A great place to work', 'de' => 'Ein großartiger Arbeitsplatz'], 'contenu' => ['en' => 'We are a great team', 'de' => 'Wir sind ein großartiges Team']]],
            ['type' => 'values', 'titre' => 'Nos valeurs', 'contenu' => null, 'data' => ['items' => [
                ['icon' => 'heart', 'title' => 'Bienveillance', 'desc' => "Nous plaçons l'humain au cœur de nos décisions"],
                ['icon' => 'rocket', 'title' => 'Innovation', 'desc' => 'Nous repoussons les limites pour créer de la valeur'],
                ['icon' => 'users', 'title' => 'Collaboration', 'desc' => 'Ensemble, nous allons plus loin'],
                ['icon' => 'shield', 'title' => 'Intégrité', 'desc' => 'Nous agissons avec transparence et éthique'],
            ]], 'ordre' => 6, 'translations' => ['titre' => ['en' => 'Our values', 'de' => 'Unsere Werte']]],
            ['type' => 'video', 'titre' => 'Découvrez Illizeo en vidéo', 'contenu' => 'Dans un monde où la technologie', 'data' => ['videos' => [
                ['title' => 'Illizeo Animation English Version', 'url' => 'https://www.youtube.com/embed/EXVC94sRWxI', 'youtube_id' => 'EXVC94sRWxI'],
                ['title' => 'Illizeo Animation - Version française', 'url' => 'https://www.youtube.com/embed/d_1DrYbQHZg', 'youtube_id' => 'd_1DrYbQHZg'],
            ]], 'ordre' => 7, 'translations' => ['titre' => ['en' => 'Discover Illizeo in video', 'de' => 'Entdecken Sie Illizeo im Video'], 'contenu' => ['en' => 'In a world where technology', 'de' => 'In einer Welt, in der Technologie']]],
            ['type' => 'team', 'titre' => "L'équipe qui vous accompagne", 'contenu' => null, 'data' => ['members' => [
                ['name' => 'Amira Laroussi', 'role' => 'Recruteur(se)', 'initials' => 'AL', 'color' => '#C2185B', 'email' => 'amira.laroussi@illizeo.com', 'phone' => '+41 22 700 01 01'],
                ['name' => 'Julie Perrin', 'role' => 'HRBP', 'initials' => 'JP', 'color' => '#8D6E63', 'email' => 'julie.perrin@illizeo.com', 'phone' => '+41 22 700 01 02'],
                ['name' => 'Romain Ndiaye', 'role' => 'DSI', 'initials' => 'RN', 'color' => '#1A73E8', 'email' => 'romain.ndiaye@illizeo.com', 'phone' => '+41 22 700 01 03'],
                ['name' => 'Mehdi Kessler', 'role' => 'Manager', 'initials' => 'MK', 'color' => '#4CAF50', 'email' => 'mehdi.kessler@illizeo.com', 'phone' => '+41 22 700 01 04'],
            ]], 'ordre' => 8, 'translations' => ['titre' => ['en' => 'The team supporting you', 'de' => 'Das Team, das Sie begleitet']]],
        ];

        foreach ($blocks as $b) {
            CompanyBlock::create($b);
        }

        // ── 16. Cooptation settings & demo data ────────────
        CooptationSetting::create([
            'mois_requis_defaut' => 6,
            'montant_defaut' => 500.00,
            'type_recompense_defaut' => 'prime',
            'description_recompense_defaut' => null,
            'actif' => true,
        ]);

        Cooptation::create([
            'referrer_name' => 'Julie Perrin',
            'referrer_email' => 'julie.perrin@illizeo.com',
            'candidate_name' => 'Lucas Martin',
            'candidate_email' => 'lucas.martin@gmail.com',
            'candidate_poste' => 'Développeur Full-Stack',
            'date_cooptation' => now()->subMonths(8),
            'date_embauche' => now()->subMonths(7),
            'mois_requis' => 6,
            'date_validation' => now()->subMonths(1),
            'statut' => 'recompense_versee',
            'type_recompense' => 'prime',
            'montant_recompense' => 500.00,
            'recompense_versee' => true,
            'date_versement' => now()->subWeeks(2),
        ]);

        Cooptation::create([
            'referrer_name' => 'Mehdi Kessler',
            'referrer_email' => 'mehdi.kessler@illizeo.com',
            'candidate_name' => 'Sophie Durand',
            'candidate_email' => 'sophie.durand@outlook.com',
            'candidate_poste' => 'Chef de projet',
            'date_cooptation' => now()->subMonths(4),
            'date_embauche' => now()->subMonths(3),
            'mois_requis' => 6,
            'date_validation' => now()->addMonths(3),
            'statut' => 'embauche',
            'type_recompense' => 'prime',
            'montant_recompense' => 500.00,
        ]);

        Cooptation::create([
            'referrer_name' => 'Amira Laroussi',
            'referrer_email' => 'amira.laroussi@illizeo.com',
            'candidate_name' => 'Thomas Bernard',
            'candidate_email' => 'thomas.bernard@gmail.com',
            'candidate_poste' => 'Data Analyst',
            'date_cooptation' => now()->subMonths(9),
            'date_embauche' => now()->subMonths(8),
            'mois_requis' => 6,
            'date_validation' => now()->subMonths(2),
            'statut' => 'valide',
            'type_recompense' => 'cadeau',
            'montant_recompense' => null,
            'description_recompense' => 'Bon voyage 300 CHF',
        ]);

        Cooptation::create([
            'referrer_name' => 'Romain Ndiaye',
            'referrer_email' => 'romain.ndiaye@illizeo.com',
            'candidate_name' => 'Camille Leroy',
            'candidate_email' => 'camille.leroy@yahoo.fr',
            'candidate_poste' => 'UX Designer',
            'date_cooptation' => now()->subWeeks(2),
            'statut' => 'en_attente',
            'type_recompense' => 'prime',
            'montant_recompense' => 500.00,
        ]);

        Cooptation::create([
            'referrer_name' => 'Julie Perrin',
            'referrer_email' => 'julie.perrin@illizeo.com',
            'candidate_name' => 'Antoine Morel',
            'candidate_email' => 'antoine.morel@gmail.com',
            'candidate_poste' => 'Consultant SAP',
            'date_cooptation' => now()->subMonths(2),
            'statut' => 'refuse',
            'type_recompense' => 'prime',
            'montant_recompense' => 500.00,
            'notes' => 'Candidature non retenue après entretien.',
        ]);

        // ── 17. Cooptation Campaigns ────────────────────────
        $campaignBackend = CooptationCampaign::firstOrCreate(
            ['titre' => 'Développeur Backend Senior'],
            [
                'description' => 'Nous recherchons un développeur backend senior (PHP/Laravel) pour renforcer notre équipe produit.',
                'departement' => 'Engineering',
                'site' => 'Genève',
                'type_contrat' => 'CDI',
                'type_recompense' => 'prime',
                'montant_recompense' => 1000.00,
                'mois_requis' => 6,
                'statut' => 'active',
                'nombre_postes' => 2,
                'priorite' => 'haute',
            ]
        );

        $campaignUx = CooptationCampaign::firstOrCreate(
            ['titre' => 'UX Designer'],
            [
                'description' => 'Rejoignez notre équipe design pour concevoir les meilleures expériences utilisateur.',
                'departement' => 'Design',
                'site' => 'Lausanne',
                'type_contrat' => 'CDI',
                'type_recompense' => 'prime',
                'montant_recompense' => 500.00,
                'mois_requis' => 6,
                'statut' => 'active',
                'nombre_postes' => 1,
                'priorite' => 'normale',
            ]
        );

        CooptationCampaign::firstOrCreate(
            ['titre' => 'Stagiaire Data'],
            [
                'description' => 'Stage de 6 mois en data science / analytics.',
                'departement' => 'Data',
                'site' => 'Genève',
                'type_contrat' => 'Stage',
                'type_recompense' => 'cadeau',
                'description_recompense' => 'Bon cadeau 100 CHF',
                'mois_requis' => 3,
                'statut' => 'fermee',
                'nombre_postes' => 1,
                'nombre_candidatures' => 4,
                'priorite' => 'normale',
            ]
        );

        // ── 18. Cooptation Points (gamification) ────────────
        $cooptations = Cooptation::all();

        foreach ($cooptations as $cooptation) {
            // Award recommendation points for all cooptations
            CooptationPoint::firstOrCreate(
                ['cooptation_id' => $cooptation->id, 'motif' => 'recommendation'],
                [
                    'user_id' => $cooptation->referrer_user_id,
                    'referrer_email' => $cooptation->referrer_email,
                    'referrer_name' => $cooptation->referrer_name,
                    'points' => 5,
                ]
            );

            // Award embauche points for hired cooptations
            if (in_array($cooptation->statut, ['embauche', 'valide', 'recompense_versee'])) {
                CooptationPoint::firstOrCreate(
                    ['cooptation_id' => $cooptation->id, 'motif' => 'embauche'],
                    [
                        'user_id' => $cooptation->referrer_user_id,
                        'referrer_email' => $cooptation->referrer_email,
                        'referrer_name' => $cooptation->referrer_name,
                        'points' => 10,
                    ]
                );
            }

            // Award validation points for validated cooptations
            if (in_array($cooptation->statut, ['valide', 'recompense_versee'])) {
                CooptationPoint::firstOrCreate(
                    ['cooptation_id' => $cooptation->id, 'motif' => 'validation'],
                    [
                        'user_id' => $cooptation->referrer_user_id,
                        'referrer_email' => $cooptation->referrer_email,
                        'referrer_name' => $cooptation->referrer_name,
                        'points' => 25,
                    ]
                );
            }

            // Award bonus points for rewarded cooptations
            if ($cooptation->statut === 'recompense_versee') {
                CooptationPoint::firstOrCreate(
                    ['cooptation_id' => $cooptation->id, 'motif' => 'bonus'],
                    [
                        'user_id' => $cooptation->referrer_user_id,
                        'referrer_email' => $cooptation->referrer_email,
                        'referrer_name' => $cooptation->referrer_name,
                        'points' => 15,
                    ]
                );
            }
        }

        // ── 19. Demo messages (IllizeoBot) ──────────────────
        // These will be created by TenantSeeder after users exist

        // ── 20. NPS & Satisfaction Surveys ──────────────────
        $surveyNps = NpsSurvey::firstOrCreate(
            ['titre' => 'NPS Onboarding'],
            [
                'description' => 'Enquête NPS envoyée à la fin du parcours d\'onboarding',
                'type' => 'nps',
                'declencheur' => 'fin_parcours',
                'questions' => [
                    ['text' => 'Sur une échelle de 0 à 10, recommanderiez-vous notre processus d\'onboarding ?', 'type' => 'nps'],
                    ['text' => 'Qu\'est-ce qui pourrait être amélioré ?', 'type' => 'text'],
                ],
                'actif' => true,
            ]
        );

        $surveySat = NpsSurvey::firstOrCreate(
            ['titre' => 'Satisfaction 3 mois'],
            [
                'description' => 'Enquête de satisfaction envoyée 3 mois après l\'arrivée',
                'type' => 'satisfaction',
                'declencheur' => 'date_specifique',
                'questions' => [
                    ['text' => 'Comment évaluez-vous votre intégration globale ?', 'type' => 'rating'],
                    ['text' => 'Vous sentez-vous bien accompagné(e) par votre manager ?', 'type' => 'rating'],
                    ['text' => 'Avez-vous des suggestions pour améliorer l\'accueil des nouveaux arrivants ?', 'type' => 'text'],
                ],
                'actif' => true,
            ]
        );

        // Demo NPS responses
        $collaborateurIds = Collaborateur::pluck('id')->take(5)->toArray();
        $npsResponses = [
            ['score' => 9, 'comment' => 'Excellent onboarding, très bien structuré !', 'months_ago' => 5],
            ['score' => 10, 'comment' => 'Parfait, rien à redire.', 'months_ago' => 4],
            ['score' => 7, 'comment' => 'Bien dans l\'ensemble, mais un peu long.', 'months_ago' => 4],
            ['score' => 6, 'comment' => 'Manque de suivi après la première semaine.', 'months_ago' => 3],
            ['score' => 8, 'comment' => null, 'months_ago' => 3],
            ['score' => 9, 'comment' => 'Super équipe RH, très disponible.', 'months_ago' => 2],
            ['score' => 4, 'comment' => 'Trop de paperasse, processus à simplifier.', 'months_ago' => 2],
            ['score' => 10, 'comment' => 'Le meilleur onboarding que j\'ai connu.', 'months_ago' => 1],
        ];

        foreach ($npsResponses as $i => $data) {
            $collabId = $collaborateurIds[$i % count($collaborateurIds)];
            NpsResponse::firstOrCreate(
                ['survey_id' => $surveyNps->id, 'collaborateur_id' => $collabId, 'score' => $data['score']],
                [
                    'answers' => [
                        ['question' => $surveyNps->questions[0]['text'], 'value' => $data['score']],
                        ['question' => $surveyNps->questions[1]['text'], 'value' => $data['comment'] ?? ''],
                    ],
                    'comment' => $data['comment'],
                    'completed_at' => now()->subMonths($data['months_ago'])->subDays(rand(0, 15)),
                ]
            );
        }

        // Demo satisfaction responses
        $satResponses = [
            ['rating' => 4.5, 'comment' => 'Très bonne intégration.', 'months_ago' => 3],
            ['rating' => 3.0, 'comment' => 'Pourrait être mieux.', 'months_ago' => 2],
            ['rating' => 5.0, 'comment' => 'Rien à dire, tout est parfait.', 'months_ago' => 1],
        ];

        foreach ($satResponses as $i => $data) {
            $collabId = $collaborateurIds[$i % count($collaborateurIds)];
            NpsResponse::firstOrCreate(
                ['survey_id' => $surveySat->id, 'collaborateur_id' => $collabId, 'rating' => $data['rating']],
                [
                    'answers' => [
                        ['question' => $surveySat->questions[0]['text'], 'value' => $data['rating']],
                        ['question' => $surveySat->questions[1]['text'], 'value' => $data['rating']],
                        ['question' => $surveySat->questions[2]['text'], 'value' => $data['comment']],
                    ],
                    'comment' => $data['comment'],
                    'completed_at' => now()->subMonths($data['months_ago'])->subDays(rand(0, 10)),
                ]
            );
        }
    }
}

