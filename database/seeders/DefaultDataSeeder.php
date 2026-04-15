<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\ActionType;
use App\Models\CollaborateurFieldConfig;
use App\Models\CompanyBlock;
use App\Models\Contrat;
use App\Models\DocumentCategorie;
use App\Models\Document;
use App\Models\EmailTemplate;
use App\Models\Groupe;
use App\Models\Integration;
use App\Models\NotificationConfig;
use App\Models\Parcours;
use App\Models\ParcoursCategorie;
use App\Models\Phase;
use App\Models\BadgeTemplate;
use App\Models\Role;
use App\Models\Workflow;
use Illuminate\Database\Seeder;

class DefaultDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Parcours Categories ──────────────────────────────
        $categories = [];
        foreach ([
            ['slug' => 'onboarding', 'nom' => 'Onboarding', 'description' => 'Intégration des nouveaux collaborateurs', 'couleur' => '#4CAF50'],
            ['slug' => 'offboarding', 'nom' => 'Offboarding', 'description' => 'Gestion des départs', 'couleur' => '#E53935'],
            ['slug' => 'crossboarding', 'nom' => 'Crossboarding', 'description' => 'Mobilité interne', 'couleur' => '#1A73E8'],
            ['slug' => 'reboarding', 'nom' => 'Reboarding', 'description' => 'Retour après absence', 'couleur' => '#F9A825'],
        ] as $cat) {
            $categories[$cat['slug']] = ParcoursCategorie::firstOrCreate(
                ['slug' => $cat['slug']],
                $cat
            );
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
            $parcours[$p['nom']] = Parcours::firstOrCreate(
                ['nom' => $p['nom']],
                [
                    'nom' => $p['nom'],
                    'categorie_id' => $categories[$p['categorie']]->id,
                    'actions_count' => $p['actions_count'],
                    'docs_count' => $p['docs_count'],
                    'collaborateurs_actifs' => $p['collaborateurs_actifs'],
                    'status' => $p['status'],
                ]
            );
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
            $ph['parcours_id'] = $parcoursId;
            $phase = Phase::firstOrCreate(
                ['nom' => $ph['nom'], 'parcours_id' => $parcoursId],
                $ph
            );
            if ($phase->wasRecentlyCreated) {
                $phase->parcours()->attach($parcoursId, ['ordre' => $ph['ordre']]);
            }
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
            $actionTypes[$at['slug']] = ActionType::firstOrCreate(
                ['slug' => $at['slug']],
                $at
            );
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
            ['titre' => "Informations d'arrivée", 'type' => 'rdv', 'phase' => 'Avant le premier jour', 'delai' => 'J-3', 'oblig' => true, 'desc' => "Date, lieu et personne à demander le premier jour. À renseigner par le RH ou le manager pour chaque collaborateur.", 'parcours' => 'Onboarding Standard', 'mode' => 'tous', 'valeurs' => [], 'duree' => '',
             'options' => ['lieu' => '', 'contact' => '', 'instructions' => '', 'a_renseigner' => true]],
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
            // passation
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
            Action::firstOrCreate(
                ['titre' => $a['titre'], 'parcours_id' => isset($parcours[$a['parcours']]) ? $parcours[$a['parcours']]->id : null],
                [
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
                ]
            );
        }

        // ── 7. Groupes ──────────────────────────────────────────
        $groupesData = [
            ['nom' => 'Nouveaux arrivants Genève', 'description' => 'Tous les collaborateurs intégrant le site de Genève', 'couleur' => '#C2185B', 'critere_type' => 'site', 'critere_valeur' => 'Genève'],
            ['nom' => 'Équipe Tech', 'description' => 'Développeurs, data analysts et IT', 'couleur' => '#1A73E8', 'critere_type' => 'departement', 'critere_valeur' => 'Tech'],
            ['nom' => 'CDI France & Suisse', 'description' => 'Tous les contrats CDI', 'couleur' => '#4CAF50', 'critere_type' => 'contrat', 'critere_valeur' => 'CDI'],
            ['nom' => 'Managers Suisse', 'description' => 'Managers sur les sites suisses', 'couleur' => '#F9A825', 'critere_type' => null, 'critere_valeur' => null],
            ['nom' => 'Stagiaires & Alternants', 'description' => 'Contrats stage et alternance', 'couleur' => '#7B5EA7', 'critere_type' => 'contrat', 'critere_valeur' => 'Stage'],
        ];

        foreach ($groupesData as $g) {
            Groupe::firstOrCreate(
                ['nom' => $g['nom']],
                $g
            );
        }

        // ── 8. Document Categories & Documents ──────────────────
        $docCatsData = [
            ['slug' => 'complementaires', 'titre' => 'Documents administratifs complémentaires', 'pieces' => [
                ['nom' => 'IBAN/BIC Suisse', 'obligatoire' => true, 'type' => 'upload'],
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
                ['nom' => 'Carte AVS', 'obligatoire' => false, 'type' => 'upload'],
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
            $cat = DocumentCategorie::firstOrCreate(
                ['slug' => $dc['slug']],
                $dc
            );

            foreach ($pieces as $piece) {
                Document::firstOrCreate(
                    ['nom' => $piece['nom'], 'categorie_id' => $cat->id],
                    [
                        'nom' => $piece['nom'],
                        'obligatoire' => $piece['obligatoire'],
                        'type' => $piece['type'],
                        'categorie_id' => $cat->id,
                    ]
                );
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
            Contrat::firstOrCreate(
                ['nom' => $c['nom']],
                $c
            );
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
            ['nom' => 'Rappel pré-arrivée J-3', 'declencheur' => "J-3 avant date d'arrivée", 'action' => 'Envoyer email pré-arrivée', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Feedback buddy J+14', 'declencheur' => 'J+14 après arrivée', 'action' => 'Envoyer demande feedback', 'destinataire' => 'Buddy / Parrain', 'actif' => true],
            ['nom' => 'Confirmation document validé', 'declencheur' => 'Document validé', 'action' => 'Envoyer email confirmation', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Envoi contrat à signer', 'declencheur' => 'Contrat prêt', 'action' => 'Envoyer email signature', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Relance signature J+3', 'declencheur' => 'J+3 après envoi signature', 'action' => 'Envoyer relance signature', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Résumé hebdomadaire collaborateur', 'declencheur' => 'Hebdomadaire (lundi)', 'action' => 'Envoyer résumé semaine', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Rapport RH — Retards hebdo', 'declencheur' => 'Hebdomadaire (lundi)', 'action' => 'Envoyer rapport retards', 'destinataire' => 'Équipe RH', 'actif' => true],
            ['nom' => 'Notification nouveau message', 'declencheur' => 'Nouveau message reçu', 'action' => 'Envoyer notification email', 'destinataire' => 'Destinataire du message', 'actif' => true],
            ['nom' => 'Notification mobilité interne', 'declencheur' => 'Parcours crossboarding créé', 'action' => 'Envoyer email mobilité', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => 'Notification retour de congé', 'declencheur' => 'Parcours reboarding créé', 'action' => 'Envoyer email bienvenue retour', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => "Envoi formulaire fin de période d'essai", 'declencheur' => "Période d'essai terminée", 'action' => 'Envoyer formulaire évaluation', 'destinataire' => 'Manager direct', 'actif' => true],
            ['nom' => 'Envoi entretien de sortie', 'declencheur' => 'Parcours offboarding créé', 'action' => 'Envoyer questionnaire exit interview', 'destinataire' => 'Collaborateur', 'actif' => true],
            ['nom' => "Envoi rapport d'étonnement J+30", 'declencheur' => 'J+30 après arrivée', 'action' => "Envoyer questionnaire rapport d'étonnement", 'destinataire' => 'Collaborateur', 'actif' => true],
        ];

        foreach ($workflowsData as $w) {
            Workflow::firstOrCreate(
                ['nom' => $w['nom']],
                $w
            );
        }

        // ── 11. Email Templates ─────────────────────────────────
        $emailTemplatesData = [
            ['nom' => 'Invitation onboarding', 'sujet' => "Bienvenue chez Illizeo – Ton parcours d'intégration", 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{date_debut}}', '{{site}}'], 'actif' => true],
            ['nom' => 'Relance documents', 'sujet' => 'Rappel : documents à compléter', 'declencheur' => 'J-7 avant deadline documents', 'variables' => ['{{prenom}}', '{{nb_docs_manquants}}', '{{date_limite}}'], 'actif' => true],
            ['nom' => 'Confirmation dossier complet', 'sujet' => 'Ton dossier est complet !', 'declencheur' => 'Tous documents validés', 'variables' => ['{{prenom}}', '{{date_debut}}'], 'actif' => true],
            ['nom' => 'Bienvenue premier jour', 'sujet' => 'C\'est le grand jour {{prenom}} !', 'declencheur' => 'J+0', 'variables' => ['{{prenom}}', '{{site}}', '{{adresse}}', '{{manager}}'], 'actif' => false],
            ['nom' => 'Fin de parcours', 'sujet' => 'Félicitations – Parcours terminé', 'declencheur' => 'Parcours complété à 100%', 'variables' => ['{{prenom}}', '{{parcours_nom}}'], 'actif' => true],
            ['nom' => 'Document refusé', 'sujet' => 'Document refusé — Action requise', 'declencheur' => 'Document refusé', 'variables' => ['{{prenom}}', '{{document_nom}}'], 'actif' => true],
            ['nom' => 'Action assignée', 'sujet' => 'Nouvelle tâche : {{action_nom}}', 'declencheur' => 'Action assignée', 'variables' => ['{{prenom}}', '{{action_nom}}', '{{date_limite}}'], 'actif' => true],
            ['nom' => 'Rappel action en retard', 'sujet' => 'Rappel : {{action_nom}} est en retard', 'declencheur' => 'J-7 avant deadline documents', 'variables' => ['{{prenom}}', '{{action_nom}}', '{{date_limite}}'], 'actif' => true],
            ['nom' => 'Validation manager requise', 'sujet' => 'Validation requise pour {{collab_nom}}', 'declencheur' => 'Tous documents validés', 'variables' => ['{{manager}}', '{{collab_nom}}', '{{parcours_nom}}'], 'actif' => true],
            ['nom' => "Fin période d'essai", 'sujet' => "Période d'essai — Évaluation de {{prenom}}", 'declencheur' => 'Parcours complété à 100%', 'variables' => ['{{manager}}', '{{prenom}}', '{{date_fin_essai}}'], 'actif' => true],
            ['nom' => "Anniversaire d'embauche", 'sujet' => 'Joyeux anniversaire professionnel {{prenom}} !', 'declencheur' => 'Parcours complété à 100%', 'variables' => ['{{prenom}}', '{{annees}}', '{{date_debut}}'], 'actif' => true],
            ['nom' => 'Offboarding — Début', 'sujet' => 'Départ de {{prenom}} — Procédure initiée', 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{date_depart}}', '{{manager}}'], 'actif' => true],
            ['nom' => 'Offboarding — Checklist IT', 'sujet' => 'Checklist IT — Désactivation des accès de {{prenom}}', 'declencheur' => 'Action assignée', 'variables' => ['{{prenom}}', '{{email}}', '{{date_depart}}'], 'actif' => true],
            ['nom' => 'Cooptation — Récompense', 'sujet' => 'Votre cooptation a été validée !', 'declencheur' => 'Tous documents validés', 'variables' => ['{{prenom}}', '{{candidat_nom}}', '{{montant}}'], 'actif' => false],
            ['nom' => 'NPS — Enquête satisfaction', 'sujet' => "Comment s'est passé votre onboarding ?", 'declencheur' => 'Parcours complété à 100%', 'variables' => ['{{prenom}}', '{{parcours_nom}}', '{{lien}}'], 'actif' => true],
            ['nom' => 'Badge obtenu', 'sujet' => 'Vous avez obtenu un nouveau badge !', 'declencheur' => 'Attribution de badge', 'variables' => ['{{prenom}}', '{{badge_nom}}'], 'actif' => true],
            ['nom' => 'Rappel pré-arrivée J-3', 'sujet' => 'Plus que 3 jours avant votre premier jour {{prenom}} !', 'declencheur' => 'J-3', 'variables' => ['{{prenom}}', '{{site}}', '{{adresse}}', '{{manager}}'], 'actif' => true],
            ['nom' => 'Notification manager — Nouvel arrivant', 'sujet' => 'Nouvel arrivant dans votre équipe : {{collab_nom}}', 'declencheur' => 'Création du collaborateur', 'variables' => ['{{manager}}', '{{collab_nom}}', '{{poste}}', '{{date_debut}}', '{{parcours_nom}}'], 'actif' => true],
            ['nom' => 'Feedback buddy / parrain', 'sujet' => "Comment se passe l'intégration de {{collab_nom}} ?", 'declencheur' => 'J+14', 'variables' => ['{{prenom}}', '{{collab_nom}}', '{{parcours_nom}}', '{{lien}}'], 'actif' => true],
            ['nom' => 'Document validé', 'sujet' => 'Votre document a été validé', 'declencheur' => 'Document validé', 'variables' => ['{{prenom}}', '{{document_nom}}'], 'actif' => true],
            ['nom' => 'Signature contrat', 'sujet' => 'Votre contrat est prêt à signer', 'declencheur' => 'Signature requise', 'variables' => ['{{prenom}}', '{{document_nom}}', '{{lien}}'], 'actif' => true],
            ['nom' => 'Relance signature', 'sujet' => 'Rappel : document en attente de signature', 'declencheur' => 'J+3 après signature requise', 'variables' => ['{{prenom}}', '{{document_nom}}', '{{lien}}'], 'actif' => true],
            ['nom' => 'Mobilité interne — Début', 'sujet' => 'Votre transition vers {{poste}} commence !', 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{poste}}', '{{site}}', '{{manager}}', '{{parcours_nom}}'], 'actif' => true],
            ['nom' => 'Retour de congé — Bienvenue', 'sujet' => 'Content de vous retrouver {{prenom}} !', 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{site}}', '{{manager}}', '{{parcours_nom}}'], 'actif' => true],
            ['nom' => 'Nouveau message reçu', 'sujet' => 'Vous avez un nouveau message de {{collab_nom}}', 'declencheur' => 'Nouveau message', 'variables' => ['{{prenom}}', '{{collab_nom}}', '{{lien}}'], 'actif' => true],
            ['nom' => 'Résumé hebdomadaire', 'sujet' => "Votre semaine d'intégration — {{prenom}}", 'declencheur' => 'Hebdomadaire (lundi)', 'variables' => ['{{prenom}}', '{{nb_docs_manquants}}', '{{parcours_nom}}', '{{lien}}'], 'actif' => true],
            ['nom' => 'Rappel RH — Actions en retard', 'sujet' => 'Rapport : {{nb_retards}} action(s) en retard', 'declencheur' => 'Hebdomadaire (lundi)', 'variables' => ['{{nb_retards}}', '{{lien}}'], 'actif' => true],
            ['nom' => "Évaluation fin de période d'essai", 'sujet' => "Évaluation de fin de période d'essai — {{collab_nom}}", 'declencheur' => 'Parcours complété à 100%', 'variables' => ['{{manager}}', '{{collab_nom}}', '{{date_fin_essai}}', '{{lien}}'], 'actif' => true, 'contenu' => "<h2>Bonjour {{manager}},</h2><p>La période d'essai de <strong>{{collab_nom}}</strong> arrive à son terme le <strong>{{date_fin_essai}}</strong>.</p><p>Merci de compléter le formulaire d'évaluation afin de confirmer ou non la poursuite du contrat.</p><p><a href='{{lien}}' style='display:inline-block;padding:10px 28px;background:#C2185B;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;'>Compléter l'évaluation</a></p>"],
            ['nom' => 'Entretien de sortie (Exit Interview)', 'sujet' => 'Votre avis compte — Entretien de sortie', 'declencheur' => 'Création du parcours', 'variables' => ['{{prenom}}', '{{date_depart}}', '{{lien}}'], 'actif' => true, 'contenu' => "<h2>Bonjour {{prenom}},</h2><p>Votre départ est prévu le <strong>{{date_depart}}</strong>. Nous aimerions recueillir votre retour d'expérience.</p><p>Ce questionnaire est confidentiel et prend environ 5 minutes.</p><p><a href='{{lien}}' style='display:inline-block;padding:10px 28px;background:#C2185B;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;'>Répondre au questionnaire</a></p>"],
            ['nom' => "Rapport d'étonnement (1 mois)", 'sujet' => "Votre regard compte — Rapport d'étonnement", 'declencheur' => 'J+30', 'variables' => ['{{prenom}}', '{{parcours_nom}}', '{{lien}}'], 'actif' => true, 'contenu' => "<h2>Bonjour {{prenom}},</h2><p>Cela fait maintenant 1 mois que vous avez rejoint l'équipe. Nous aimerions recueillir votre regard neuf sur notre entreprise et notre processus d'intégration.</p><p>Ce questionnaire est confidentiel et prend environ 5 minutes.</p><p><a href='{{lien}}' style='display:inline-block;padding:10px 28px;background:#C2185B;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;'>Partager mon retour</a></p>"],
        ];

        foreach ($emailTemplatesData as $et) {
            EmailTemplate::firstOrCreate(
                ['nom' => $et['nom']],
                $et
            );
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
            'Nouveau message reçu', 'Document validé', 'Badge obtenu',
            'Cooptation — Statut mis à jour', 'Parcours terminé',
            'Signature de contrat requise', 'Rappel pré-arrivée J-3',
            'Feedback buddy / parrain demandé',
            'Mobilité interne — Début de parcours',
            'Retour de congé — Parcours initié',
            'Résumé hebdomadaire collaborateur',
            'NPS — Nouvelle enquête disponible',
        ];

        foreach ($notifications as $notif) {
            NotificationConfig::firstOrCreate(
                ['nom' => $notif, 'categorie' => 'general'],
                [
                    'nom' => $notif,
                    'canal' => 'email',
                    'actif' => true,
                    'categorie' => 'general',
                ]
            );
        }

        // Resource notification
        NotificationConfig::firstOrCreate(
            ['nom' => 'Événements', 'categorie' => 'ressource'],
            [
                'nom' => 'Événements',
                'canal' => 'email',
                'actif' => true,
                'categorie' => 'ressource',
            ]
        );

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
            ['provider' => 'taleez', 'categorie' => 'ats', 'nom' => 'Taleez', 'config' => ['api_key' => '', 'company_slug' => ''], 'actif' => false, 'connecte' => false],
            // SIRH
            ['provider' => 'sap', 'categorie' => 'sirh', 'nom' => 'SAP SuccessFactors', 'config' => ['base_url' => '', 'company_id' => '', 'username' => '', 'password' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'personio', 'categorie' => 'sirh', 'nom' => 'Personio', 'config' => ['client_id' => '', 'client_secret' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'lucca', 'categorie' => 'sirh', 'nom' => 'Lucca', 'config' => ['subdomain' => '', 'api_key' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'bamboohr', 'categorie' => 'sirh', 'nom' => 'BambooHR', 'config' => ['company_domain' => '', 'api_key' => ''], 'actif' => false, 'connecte' => false],
            ['provider' => 'workday', 'categorie' => 'sirh', 'nom' => 'Workday HCM', 'config' => ['host' => '', 'tenant' => '', 'client_id' => '', 'client_secret' => '', 'refresh_token' => ''], 'actif' => false, 'connecte' => false],
        ];

        foreach ($integrations as $i) {
            Integration::firstOrCreate(
                ['provider' => $i['provider']],
                $i
            );
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
            // Job Information
            ['field_key' => 'job_title', 'label' => 'Intitulé du poste', 'label_en' => 'Job title', 'section' => 'job', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 1],
            ['field_key' => 'job_family', 'label' => 'Famille de métiers', 'label_en' => 'Job family', 'section' => 'job', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 2],
            ['field_key' => 'job_code', 'label' => 'Code métier', 'label_en' => 'Job code', 'section' => 'job', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 3],
            ['field_key' => 'job_level', 'label' => 'Niveau de poste', 'label_en' => 'Job level', 'section' => 'job', 'field_type' => 'list', 'list_values' => ['Junior', 'Confirmé', 'Senior', 'Lead', 'Principal', 'Director', 'VP', 'C-Level'], 'actif' => true, 'obligatoire' => false, 'ordre' => 4],
            ['field_key' => 'employment_type', 'label' => "Type d'emploi", 'label_en' => 'Employment type', 'section' => 'job', 'field_type' => 'list', 'list_values' => ['CDI', 'CDD', 'Stage', 'Alternance', 'Freelance', 'Intérim', 'Apprentissage'], 'actif' => true, 'obligatoire' => false, 'ordre' => 5],
            ['field_key' => 'date_fin_contrat', 'label' => 'Date de fin de contrat', 'label_en' => 'Contract end date', 'section' => 'job', 'field_type' => 'date', 'actif' => true, 'obligatoire' => false, 'ordre' => 6],
            ['field_key' => 'motif_embauche', 'label' => "Motif d'embauche", 'label_en' => 'Hiring reason', 'section' => 'job', 'field_type' => 'list', 'list_values' => ['Création de poste', 'Remplacement', 'Surcroît d\'activité', 'Réorganisation', 'Autre'], 'actif' => true, 'obligatoire' => false, 'ordre' => 7],
            // Position Information
            ['field_key' => 'position_title', 'label' => 'Intitulé de la position', 'label_en' => 'Position title', 'section' => 'position', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 1],
            ['field_key' => 'position_code', 'label' => 'Code position', 'label_en' => 'Position code', 'section' => 'position', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 2],
            ['field_key' => 'business_unit', 'label' => 'Business Unit', 'label_en' => 'Business unit', 'section' => 'position', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 3],
            ['field_key' => 'division', 'label' => 'Division', 'label_en' => 'Division', 'section' => 'position', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 4],
            ['field_key' => 'cost_center', 'label' => 'Centre de coût', 'label_en' => 'Cost center', 'section' => 'position', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 5],
            ['field_key' => 'location_code', 'label' => 'Code site', 'label_en' => 'Location code', 'section' => 'position', 'field_type' => 'text', 'actif' => true, 'obligatoire' => false, 'ordre' => 6],
            ['field_key' => 'manager_id', 'label' => 'Manager (ID)', 'label_en' => 'Manager ID', 'section' => 'position', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 7],
            ['field_key' => 'dotted_line_manager', 'label' => 'Manager fonctionnel', 'label_en' => 'Dotted-line manager', 'section' => 'position', 'field_type' => 'text', 'actif' => false, 'obligatoire' => false, 'ordre' => 8],
            ['field_key' => 'work_schedule', 'label' => 'Horaire de travail', 'label_en' => 'Work schedule', 'section' => 'position', 'field_type' => 'list', 'list_values' => ['Temps plein', 'Temps partiel', 'Horaires flexibles', 'Travail posté'], 'actif' => true, 'obligatoire' => false, 'ordre' => 9],
            ['field_key' => 'fte', 'label' => 'FTE (équivalent temps plein)', 'label_en' => 'FTE', 'section' => 'position', 'field_type' => 'number', 'actif' => true, 'obligatoire' => false, 'ordre' => 10],
        ];

        foreach ($fieldConfigs as $fc) {
            CollaborateurFieldConfig::firstOrCreate(
                ['field_key' => $fc['field_key']],
                $fc
            );
        }

        // ── 15. Company page blocks ──────────────────────────
        $blocks = [
            ['type' => 'hero', 'titre' => 'Bienvenue chez nous', 'contenu' => "Nous sommes ravis de vous accueillir dans l'équipe. Découvrez notre entreprise, notre mission et nos valeurs.", 'data' => ['subtitle' => 'Votre aventure commence ici', 'image_url' => ''], 'ordre' => 1],
            ['type' => 'text', 'titre' => 'À propos de nous', 'contenu' => "Présentez votre entreprise ici. Décrivez votre activité, votre histoire et ce qui fait votre force.", 'data' => ['icon' => 'building'], 'ordre' => 2],
            ['type' => 'mission', 'titre' => 'Notre mission', 'contenu' => "Décrivez la mission de votre entreprise et ce qui vous anime au quotidien.", 'data' => ['number' => '01'], 'ordre' => 3],
            ['type' => 'stats', 'titre' => 'Nos chiffres clés', 'contenu' => 'Quelques chiffres qui parlent', 'data' => ['badge' => '', 'items' => [
                ['value' => '—', 'label' => 'Collaborateurs'],
                ['value' => '—', 'label' => 'Sites'],
                ['value' => '—', 'label' => 'Pays'],
            ]], 'ordre' => 4],
            ['type' => 'values', 'titre' => 'Nos valeurs', 'contenu' => null, 'data' => ['items' => [
                ['icon' => 'heart', 'title' => 'Bienveillance', 'desc' => "Nous plaçons l'humain au cœur de nos décisions"],
                ['icon' => 'rocket', 'title' => 'Innovation', 'desc' => 'Nous repoussons les limites pour créer de la valeur'],
                ['icon' => 'users', 'title' => 'Collaboration', 'desc' => 'Ensemble, nous allons plus loin'],
                ['icon' => 'shield', 'title' => 'Intégrité', 'desc' => 'Nous agissons avec transparence et éthique'],
            ]], 'ordre' => 5],
            ['type' => 'team', 'titre' => "L'équipe qui vous accompagne", 'contenu' => null, 'data' => ['members' => []], 'ordre' => 6],
        ];

        foreach ($blocks as $b) {
            CompanyBlock::firstOrCreate(
                ['type' => $b['type'], 'ordre' => $b['ordre']],
                $b
            );
        }

        // ── 16. Badge Templates ─────────────────────────────────
        $badgeTemplates = [
            ['nom' => 'Parcours terminé', 'description' => 'Félicitations ! Vous avez terminé votre parcours d\'intégration.', 'icon' => 'trophy', 'color' => '#F9A825', 'critere' => 'parcours_complete'],
            ['nom' => 'Documents complets', 'description' => 'Tous vos documents administratifs sont validés.', 'icon' => 'file-check', 'color' => '#4CAF50', 'critere' => 'docs_complete'],
            ['nom' => 'Premier message', 'description' => 'Vous avez envoyé votre premier message.', 'icon' => 'message-circle', 'color' => '#1A73E8', 'critere' => 'premier_message'],
            ['nom' => 'Première semaine', 'description' => 'Vous avez complété votre première semaine !', 'icon' => 'calendar-check', 'color' => '#7B5EA7', 'critere' => 'first_week'],
            ['nom' => 'Premier mois', 'description' => 'Un mois déjà ! Bravo pour votre intégration.', 'icon' => 'star', 'color' => '#C2185B', 'critere' => 'first_month'],
            ['nom' => 'Super Coopteur', 'description' => 'Vous avez recommandé un candidat qui a été embauché.', 'icon' => 'handshake', 'color' => '#E91E8C', 'critere' => 'cooptation'],
            ['nom' => 'NPS Champion', 'description' => 'Merci d\'avoir partagé votre feedback !', 'icon' => 'smile', 'color' => '#00897B', 'critere' => 'nps_complete'],
            ['nom' => 'Bienvenue', 'description' => 'Bienvenue dans l\'équipe !', 'icon' => 'party-popper', 'color' => '#FF6B35', 'critere' => 'manual'],
        ];

        foreach ($badgeTemplates as $bt) {
            BadgeTemplate::firstOrCreate(['nom' => $bt['nom']], $bt);
        }

        // ── 18. Equipment Types par défaut ──────────────────────
        $equipmentTypes = [
            // Matériel
            ['nom' => 'Ordinateur portable', 'icon' => 'laptop', 'categorie' => 'materiel', 'description' => 'PC portable, MacBook, Chromebook...'],
            ['nom' => 'Écran', 'icon' => 'monitor', 'categorie' => 'materiel', 'description' => 'Écran externe, moniteur...'],
            ['nom' => 'Téléphone', 'icon' => 'phone', 'categorie' => 'materiel', 'description' => 'Smartphone professionnel'],
            ['nom' => 'Badge / Clé', 'icon' => 'key', 'categorie' => 'materiel', 'description' => 'Badge d\'accès, clé de bureau, carte parking'],
            ['nom' => 'Casque / Audio', 'icon' => 'headphones', 'categorie' => 'materiel', 'description' => 'Casque, écouteurs, micro'],
            ['nom' => 'Clavier / Souris', 'icon' => 'mouse', 'categorie' => 'materiel', 'description' => 'Clavier, souris, trackpad'],
            ['nom' => 'Mobilier', 'icon' => 'armchair', 'categorie' => 'materiel', 'description' => 'Bureau, chaise, caisson'],
            ['nom' => 'Véhicule', 'icon' => 'car', 'categorie' => 'materiel', 'description' => 'Véhicule de fonction'],
            ['nom' => 'Autre matériel', 'icon' => 'package', 'categorie' => 'materiel', 'description' => 'Autre équipement'],
            // Licences
            ['nom' => 'Microsoft 365', 'icon' => 'globe', 'categorie' => 'licence', 'description' => 'Licence Office 365, Teams, OneDrive'],
            ['nom' => 'Slack', 'icon' => 'message-circle', 'categorie' => 'licence', 'description' => 'Licence Slack workspace'],
            ['nom' => 'GitHub / GitLab', 'icon' => 'code', 'categorie' => 'licence', 'description' => 'Accès dépôt de code'],
            ['nom' => 'Jira / Confluence', 'icon' => 'clipboard', 'categorie' => 'licence', 'description' => 'Licence Atlassian'],
            ['nom' => 'VPN', 'icon' => 'shield', 'categorie' => 'licence', 'description' => 'Accès VPN entreprise'],
            ['nom' => 'ERP / CRM', 'icon' => 'database', 'categorie' => 'licence', 'description' => 'SAP, Salesforce, HubSpot...'],
            ['nom' => 'Autre licence', 'icon' => 'key', 'categorie' => 'licence', 'description' => 'Autre licence logicielle'],
        ];
        foreach ($equipmentTypes as $et) {
            \App\Models\EquipmentType::firstOrCreate(['nom' => $et['nom']], $et);
        }

        // ── 18b. Equipment Packages par défaut ──────────────────
        if (\App\Models\EquipmentPackage::count() === 0) {
            $pcType = \App\Models\EquipmentType::where('nom', 'Ordinateur portable')->first();
            $mouseType = \App\Models\EquipmentType::where('nom', 'Clavier / Souris')->first();
            $screenType = \App\Models\EquipmentType::where('nom', 'Écran')->first();
            $headsetType = \App\Models\EquipmentType::where('nom', 'Casque / Audio')->first();
            $badgeType = \App\Models\EquipmentType::where('nom', 'Badge / Clé')->first();
            $m365Type = \App\Models\EquipmentType::where('nom', 'Microsoft 365')->first();
            $vpnType = \App\Models\EquipmentType::where('nom', 'VPN')->first();

            $pkgDev = \App\Models\EquipmentPackage::create(['nom' => 'Package IT Développeur', 'description' => 'Équipement standard pour les développeurs', 'icon' => 'laptop', 'couleur' => '#1A73E8']);
            if ($pcType) $pkgDev->items()->create(['equipment_type_id' => $pcType->id, 'quantite' => 1]);
            if ($screenType) $pkgDev->items()->create(['equipment_type_id' => $screenType->id, 'quantite' => 2, 'notes' => 'Double écran']);
            if ($mouseType) $pkgDev->items()->create(['equipment_type_id' => $mouseType->id, 'quantite' => 1]);
            if ($headsetType) $pkgDev->items()->create(['equipment_type_id' => $headsetType->id, 'quantite' => 1]);
            if ($badgeType) $pkgDev->items()->create(['equipment_type_id' => $badgeType->id, 'quantite' => 1]);
            if ($m365Type) $pkgDev->items()->create(['equipment_type_id' => $m365Type->id, 'quantite' => 1]);
            if ($vpnType) $pkgDev->items()->create(['equipment_type_id' => $vpnType->id, 'quantite' => 1]);

            $pkgStd = \App\Models\EquipmentPackage::create(['nom' => 'Package Standard', 'description' => 'Équipement de base pour les collaborateurs', 'icon' => 'package', 'couleur' => '#4CAF50']);
            if ($pcType) $pkgStd->items()->create(['equipment_type_id' => $pcType->id, 'quantite' => 1]);
            if ($screenType) $pkgStd->items()->create(['equipment_type_id' => $screenType->id, 'quantite' => 1]);
            if ($mouseType) $pkgStd->items()->create(['equipment_type_id' => $mouseType->id, 'quantite' => 1]);
            if ($badgeType) $pkgStd->items()->create(['equipment_type_id' => $badgeType->id, 'quantite' => 1]);
            if ($m365Type) $pkgStd->items()->create(['equipment_type_id' => $m365Type->id, 'quantite' => 1]);

            $pkgMgr = \App\Models\EquipmentPackage::create(['nom' => 'Package Manager', 'description' => 'Équipement pour les managers et cadres', 'icon' => 'crown', 'couleur' => '#7B5EA7']);
            if ($pcType) $pkgMgr->items()->create(['equipment_type_id' => $pcType->id, 'quantite' => 1]);
            if ($screenType) $pkgMgr->items()->create(['equipment_type_id' => $screenType->id, 'quantite' => 1]);
            if ($mouseType) $pkgMgr->items()->create(['equipment_type_id' => $mouseType->id, 'quantite' => 1]);
            if ($headsetType) $pkgMgr->items()->create(['equipment_type_id' => $headsetType->id, 'quantite' => 1]);
            if ($badgeType) $pkgMgr->items()->create(['equipment_type_id' => $badgeType->id, 'quantite' => 1]);
            if ($m365Type) $pkgMgr->items()->create(['equipment_type_id' => $m365Type->id, 'quantite' => 1]);
            if ($vpnType) $pkgMgr->items()->create(['equipment_type_id' => $vpnType->id, 'quantite' => 1]);
        }

        // ── 18c. Inventaire équipements par défaut ─────────────
        if (\App\Models\Equipment::count() === 0) {
            $types = \App\Models\EquipmentType::all()->keyBy('nom');
            $collabs = \App\Models\Collaborateur::take(5)->get();

            $inventory = [
                // Ordinateurs portables
                ['type' => 'Ordinateur portable', 'nom' => 'MacBook Pro 14" M3', 'marque' => 'Apple', 'modele' => 'MacBook Pro 14 2024', 'numero_serie' => 'C02ZN1KDLVCG', 'etat' => 'attribue', 'valeur' => 2499.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Ordinateur portable', 'nom' => 'MacBook Pro 14" M3', 'marque' => 'Apple', 'modele' => 'MacBook Pro 14 2024', 'numero_serie' => 'C02ZN1KDLVCH', 'etat' => 'attribue', 'valeur' => 2499.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Ordinateur portable', 'nom' => 'ThinkPad X1 Carbon G11', 'marque' => 'Lenovo', 'modele' => 'X1 Carbon Gen 11', 'numero_serie' => 'PF3XYRG2', 'etat' => 'disponible', 'valeur' => 1899.00, 'date_achat' => '2024-06-10'],
                ['type' => 'Ordinateur portable', 'nom' => 'ThinkPad X1 Carbon G11', 'marque' => 'Lenovo', 'modele' => 'X1 Carbon Gen 11', 'numero_serie' => 'PF3XYRG3', 'etat' => 'disponible', 'valeur' => 1899.00, 'date_achat' => '2024-06-10'],
                ['type' => 'Ordinateur portable', 'nom' => 'Dell Latitude 5540', 'marque' => 'Dell', 'modele' => 'Latitude 5540', 'numero_serie' => 'DL5540-2024-001', 'etat' => 'en_reparation', 'valeur' => 1299.00, 'date_achat' => '2024-01-20', 'notes' => 'Batterie à remplacer'],
                ['type' => 'Ordinateur portable', 'nom' => 'MacBook Air M2', 'marque' => 'Apple', 'modele' => 'MacBook Air 13 2023', 'numero_serie' => 'C02YM3KDLVCF', 'etat' => 'attribue', 'valeur' => 1499.00, 'date_achat' => '2023-09-01'],

                // Écrans
                ['type' => 'Écran', 'nom' => 'Dell UltraSharp 27" 4K', 'marque' => 'Dell', 'modele' => 'U2723QE', 'numero_serie' => 'DU27-2024-001', 'etat' => 'attribue', 'valeur' => 549.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Écran', 'nom' => 'Dell UltraSharp 27" 4K', 'marque' => 'Dell', 'modele' => 'U2723QE', 'numero_serie' => 'DU27-2024-002', 'etat' => 'attribue', 'valeur' => 549.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Écran', 'nom' => 'Dell UltraSharp 27" 4K', 'marque' => 'Dell', 'modele' => 'U2723QE', 'numero_serie' => 'DU27-2024-003', 'etat' => 'disponible', 'valeur' => 549.00, 'date_achat' => '2024-06-10'],
                ['type' => 'Écran', 'nom' => 'LG 34" UltraWide', 'marque' => 'LG', 'modele' => '34WN80C-B', 'numero_serie' => 'LG34-2024-001', 'etat' => 'attribue', 'valeur' => 699.00, 'date_achat' => '2024-02-20'],
                ['type' => 'Écran', 'nom' => 'Samsung 24" FHD', 'marque' => 'Samsung', 'modele' => 'S24A600', 'numero_serie' => 'SS24-2024-001', 'etat' => 'disponible', 'valeur' => 289.00, 'date_achat' => '2024-04-01'],

                // Téléphones
                ['type' => 'Téléphone', 'nom' => 'iPhone 15 Pro', 'marque' => 'Apple', 'modele' => 'iPhone 15 Pro 256GB', 'numero_serie' => 'DNQXR3N0GR', 'etat' => 'attribue', 'valeur' => 1329.00, 'date_achat' => '2024-01-10'],
                ['type' => 'Téléphone', 'nom' => 'Samsung Galaxy S24', 'marque' => 'Samsung', 'modele' => 'Galaxy S24 128GB', 'numero_serie' => 'R5CW30BN7MJ', 'etat' => 'disponible', 'valeur' => 899.00, 'date_achat' => '2024-05-15'],

                // Casques
                ['type' => 'Casque / Audio', 'nom' => 'Jabra Evolve2 75', 'marque' => 'Jabra', 'modele' => 'Evolve2 75 UC', 'numero_serie' => 'JAB-EV2-001', 'etat' => 'attribue', 'valeur' => 299.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Casque / Audio', 'nom' => 'Jabra Evolve2 75', 'marque' => 'Jabra', 'modele' => 'Evolve2 75 UC', 'numero_serie' => 'JAB-EV2-002', 'etat' => 'attribue', 'valeur' => 299.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Casque / Audio', 'nom' => 'Poly Voyager Focus 2', 'marque' => 'Poly', 'modele' => 'Voyager Focus 2 UC', 'numero_serie' => 'PLY-VF2-001', 'etat' => 'disponible', 'valeur' => 249.00, 'date_achat' => '2024-06-10'],

                // Claviers / Souris
                ['type' => 'Clavier / Souris', 'nom' => 'Logitech MX Keys + MX Master 3S', 'marque' => 'Logitech', 'modele' => 'MX Keys Combo', 'numero_serie' => 'LGT-MX-001', 'etat' => 'attribue', 'valeur' => 199.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Clavier / Souris', 'nom' => 'Logitech MX Keys + MX Master 3S', 'marque' => 'Logitech', 'modele' => 'MX Keys Combo', 'numero_serie' => 'LGT-MX-002', 'etat' => 'attribue', 'valeur' => 199.00, 'date_achat' => '2024-03-15'],
                ['type' => 'Clavier / Souris', 'nom' => 'Apple Magic Keyboard + Trackpad', 'marque' => 'Apple', 'modele' => 'Magic Keyboard FR', 'numero_serie' => 'APL-MK-001', 'etat' => 'disponible', 'valeur' => 249.00, 'date_achat' => '2024-06-10'],

                // Badges
                ['type' => 'Badge / Clé', 'nom' => 'Badge accès siège', 'marque' => 'HID', 'modele' => 'iCLASS SE', 'numero_serie' => 'BDG-001', 'etat' => 'attribue', 'valeur' => 15.00, 'date_achat' => '2024-01-01'],
                ['type' => 'Badge / Clé', 'nom' => 'Badge accès siège', 'marque' => 'HID', 'modele' => 'iCLASS SE', 'numero_serie' => 'BDG-002', 'etat' => 'attribue', 'valeur' => 15.00, 'date_achat' => '2024-01-01'],
                ['type' => 'Badge / Clé', 'nom' => 'Badge accès siège', 'marque' => 'HID', 'modele' => 'iCLASS SE', 'numero_serie' => 'BDG-003', 'etat' => 'disponible', 'valeur' => 15.00, 'date_achat' => '2024-01-01'],
                ['type' => 'Badge / Clé', 'nom' => 'Clé bureau B204', 'marque' => null, 'modele' => null, 'numero_serie' => 'KEY-B204-001', 'etat' => 'attribue', 'valeur' => 5.00, 'date_achat' => '2024-01-01'],

                // Mobilier
                ['type' => 'Mobilier', 'nom' => 'Bureau assis-debout', 'marque' => 'FlexiSpot', 'modele' => 'E7 Pro', 'numero_serie' => 'FSP-E7-001', 'etat' => 'attribue', 'valeur' => 599.00, 'date_achat' => '2024-02-01'],
                ['type' => 'Mobilier', 'nom' => 'Chaise ergonomique', 'marque' => 'Herman Miller', 'modele' => 'Aeron Remastered', 'numero_serie' => 'HM-AER-001', 'etat' => 'attribue', 'valeur' => 1490.00, 'date_achat' => '2024-02-01'],
                ['type' => 'Mobilier', 'nom' => 'Chaise ergonomique', 'marque' => 'Steelcase', 'modele' => 'Leap V2', 'numero_serie' => 'SC-LEAP-001', 'etat' => 'disponible', 'valeur' => 1199.00, 'date_achat' => '2024-04-15'],

                // Licences
                ['type' => 'Microsoft 365', 'nom' => 'Microsoft 365 Business Premium', 'marque' => 'Microsoft', 'modele' => 'Business Premium', 'numero_serie' => 'M365-BP-001', 'etat' => 'attribue', 'valeur' => 264.00, 'date_achat' => '2024-01-01', 'notes' => 'Abonnement annuel'],
                ['type' => 'Microsoft 365', 'nom' => 'Microsoft 365 Business Premium', 'marque' => 'Microsoft', 'modele' => 'Business Premium', 'numero_serie' => 'M365-BP-002', 'etat' => 'attribue', 'valeur' => 264.00, 'date_achat' => '2024-01-01'],
                ['type' => 'Microsoft 365', 'nom' => 'Microsoft 365 Business Premium', 'marque' => 'Microsoft', 'modele' => 'Business Premium', 'numero_serie' => 'M365-BP-003', 'etat' => 'disponible', 'valeur' => 264.00, 'date_achat' => '2024-01-01'],
                ['type' => 'Slack', 'nom' => 'Slack Business+', 'marque' => 'Slack', 'modele' => 'Business+', 'numero_serie' => 'SLK-BP-001', 'etat' => 'attribue', 'valeur' => 150.00, 'date_achat' => '2024-01-01'],
                ['type' => 'Slack', 'nom' => 'Slack Business+', 'marque' => 'Slack', 'modele' => 'Business+', 'numero_serie' => 'SLK-BP-002', 'etat' => 'attribue', 'valeur' => 150.00, 'date_achat' => '2024-01-01'],
                ['type' => 'GitHub / GitLab', 'nom' => 'GitHub Enterprise', 'marque' => 'GitHub', 'modele' => 'Enterprise Cloud', 'numero_serie' => 'GH-ENT-001', 'etat' => 'attribue', 'valeur' => 252.00, 'date_achat' => '2024-01-01'],
                ['type' => 'VPN', 'nom' => 'NordVPN Teams', 'marque' => 'NordVPN', 'modele' => 'Teams', 'numero_serie' => 'NVP-T-001', 'etat' => 'attribue', 'valeur' => 84.00, 'date_achat' => '2024-01-01'],
                ['type' => 'VPN', 'nom' => 'NordVPN Teams', 'marque' => 'NordVPN', 'modele' => 'Teams', 'numero_serie' => 'NVP-T-002', 'etat' => 'disponible', 'valeur' => 84.00, 'date_achat' => '2024-01-01'],
                ['type' => 'Jira / Confluence', 'nom' => 'Atlassian Cloud Premium', 'marque' => 'Atlassian', 'modele' => 'Cloud Premium', 'numero_serie' => 'ATL-CP-001', 'etat' => 'attribue', 'valeur' => 180.00, 'date_achat' => '2024-01-01'],
                ['type' => 'ERP / CRM', 'nom' => 'HubSpot Sales Pro', 'marque' => 'HubSpot', 'modele' => 'Sales Hub Pro', 'numero_serie' => 'HS-SP-001', 'etat' => 'attribue', 'valeur' => 540.00, 'date_achat' => '2024-01-01'],

                // Véhicule
                ['type' => 'Véhicule', 'nom' => 'Tesla Model 3', 'marque' => 'Tesla', 'modele' => 'Model 3 Long Range 2024', 'numero_serie' => '5YJ3E1EA8RF', 'etat' => 'attribue', 'valeur' => 42990.00, 'date_achat' => '2024-04-01'],

                // Retiré
                ['type' => 'Ordinateur portable', 'nom' => 'MacBook Pro 13" 2019', 'marque' => 'Apple', 'modele' => 'MacBook Pro 13 2019', 'numero_serie' => 'C02YK1KDLVCA', 'etat' => 'retire', 'valeur' => 1799.00, 'date_achat' => '2019-11-15', 'notes' => 'Hors garantie, remplacé'],
            ];

            $assignIdx = 0;
            foreach ($inventory as $item) {
                $typeModel = $types->get($item['type']);
                if (!$typeModel) continue;
                $equip = \App\Models\Equipment::create([
                    'equipment_type_id' => $typeModel->id,
                    'nom' => $item['nom'],
                    'marque' => $item['marque'] ?? null,
                    'modele' => $item['modele'] ?? null,
                    'numero_serie' => $item['numero_serie'] ?? null,
                    'etat' => $item['etat'],
                    'valeur' => $item['valeur'] ?? null,
                    'date_achat' => $item['date_achat'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
                // Assign some to collaborateurs
                if ($item['etat'] === 'attribue' && $collabs->count() > 0) {
                    $collab = $collabs[$assignIdx % $collabs->count()];
                    $equip->update([
                        'collaborateur_id' => $collab->id,
                        'assigned_at' => now()->subDays(rand(10, 120)),
                    ]);
                    $assignIdx++;
                }
            }
        }

        // ── 19. Signature Documents par défaut ─────────────────
        $signDocs = [
            ['titre' => 'Règlement intérieur', 'description' => 'Le collaborateur doit lire et accepter le règlement intérieur de l\'entreprise.', 'type' => 'lecture', 'obligatoire' => true],
            ['titre' => 'Charte informatique', 'description' => 'Conditions d\'utilisation du matériel informatique et des systèmes d\'information.', 'type' => 'lecture', 'obligatoire' => true],
            ['titre' => 'Droit à l\'image', 'description' => 'Autorisation d\'utilisation de l\'image du collaborateur à des fins professionnelles.', 'type' => 'signature', 'obligatoire' => false],
            ['titre' => 'Accord de confidentialité (NDA)', 'description' => 'Engagement de non-divulgation des informations confidentielles de l\'entreprise.', 'type' => 'signature', 'obligatoire' => true],
            ['titre' => 'Politique RGPD', 'description' => 'Information sur le traitement des données personnelles conformément au RGPD.', 'type' => 'lecture', 'obligatoire' => true],
        ];
        foreach ($signDocs as $sd) {
            \App\Models\SignatureDocument::firstOrCreate(['titre' => $sd['titre']], $sd);
        }

        // ── 17. NPS Surveys par défaut ──────────────────────────
        $surveys = [
            [
                'titre' => 'NPS Onboarding',
                'description' => "Enquête NPS envoyée à la fin du parcours d'onboarding pour mesurer la satisfaction globale.",
                'type' => 'nps',
                'declencheur' => 'fin_parcours',
                'actif' => true,
                'questions' => [
                    ['text' => "Sur une échelle de 0 à 10, recommanderiez-vous notre processus d'onboarding à un collègue ?", 'type' => 'nps'],
                    ['text' => "Comment évaluez-vous l'accompagnement de votre manager ? (1-5)", 'type' => 'rating'],
                    ['text' => "Qu'est-ce qui pourrait être amélioré dans votre intégration ?", 'type' => 'text'],
                ],
                'translations' => [
                    'titre' => ['en' => 'NPS Onboarding'],
                    'description' => ['en' => 'NPS survey sent at the end of the onboarding process to measure overall satisfaction.'],
                    'questions' => ['en' => [
                        ['text' => 'On a scale of 0 to 10, would you recommend our onboarding process to a colleague?'],
                        ['text' => "How would you rate your manager's support? (1-5)"],
                        ['text' => 'What could be improved in your onboarding experience?'],
                    ]],
                ],
            ],
            [
                'titre' => 'Satisfaction 3 mois',
                'description' => "Enquête de satisfaction envoyée 3 mois après l'arrivée du collaborateur.",
                'type' => 'satisfaction',
                'declencheur' => 'date_specifique',
                'actif' => true,
                'questions' => [
                    ['text' => "Comment évaluez-vous votre intégration globale ? (1-5)", 'type' => 'rating'],
                    ['text' => "Vous sentez-vous bien accompagné(e) par votre manager ? (1-5)", 'type' => 'rating'],
                    ['text' => "Les outils et ressources mis à disposition sont-ils suffisants ? (1-5)", 'type' => 'rating'],
                    ['text' => "Avez-vous des suggestions pour améliorer l'accueil des nouveaux arrivants ?", 'type' => 'text'],
                ],
                'translations' => [
                    'titre' => ['en' => '3-Month Satisfaction'],
                    'description' => ['en' => "Satisfaction survey sent 3 months after the employee's arrival."],
                    'questions' => ['en' => [
                        ['text' => 'How would you rate your overall integration? (1-5)'],
                        ['text' => 'Do you feel well supported by your manager? (1-5)'],
                        ['text' => 'Are the tools and resources provided sufficient? (1-5)'],
                        ['text' => 'Do you have any suggestions to improve the onboarding experience for new employees?'],
                    ]],
                ],
            ],
            [
                'titre' => "Évaluation fin de période d'essai",
                'description' => "Formulaire d'évaluation rempli par le manager à la fin de la période d'essai du collaborateur.",
                'type' => 'custom',
                'declencheur' => 'manuel',
                'actif' => true,
                'questions' => [
                    ['text' => "Maîtrise des compétences techniques requises pour le poste", 'type' => 'rating'],
                    ['text' => "Capacité d'adaptation et d'apprentissage", 'type' => 'rating'],
                    ['text' => "Qualité du travail et respect des délais", 'type' => 'rating'],
                    ['text' => "Intégration dans l'équipe et collaboration", 'type' => 'rating'],
                    ['text' => "Autonomie et prise d'initiative", 'type' => 'rating'],
                    ['text' => "Respect des valeurs et de la culture d'entreprise", 'type' => 'rating'],
                    ['text' => "Communication et relationnel", 'type' => 'rating'],
                    ['text' => "Assiduité et ponctualité", 'type' => 'rating'],
                    ['text' => "Recommandation", 'type' => 'choice', 'options' => ['Confirmation en CDI', "Renouvellement période d'essai", 'Non-renouvellement']],
                    ['text' => "Points forts observés durant la période d'essai", 'type' => 'text'],
                    ['text' => "Axes d'amélioration identifiés", 'type' => 'text'],
                    ['text' => "Objectifs fixés pour les 6 prochains mois", 'type' => 'text'],
                    ['text' => "Commentaire libre du manager", 'type' => 'text'],
                ],
                'translations' => [
                    'titre' => ['en' => 'Probation Period Evaluation'],
                    'description' => ['en' => 'Evaluation form filled by the manager at the end of the probation period.'],
                    'questions' => ['en' => [
                        ['text' => 'Mastery of technical skills required for the position'],
                        ['text' => 'Adaptability and learning ability'],
                        ['text' => 'Quality of work and meeting deadlines'],
                        ['text' => 'Team integration and collaboration'],
                        ['text' => 'Autonomy and initiative'],
                        ['text' => 'Respect for company values and culture'],
                        ['text' => 'Communication and interpersonal skills'],
                        ['text' => 'Attendance and punctuality'],
                        ['text' => 'Recommendation', 'options' => ['Confirm permanent contract', 'Extend probation period', 'Do not renew']],
                        ['text' => 'Strengths observed during the probation period'],
                        ['text' => 'Areas for improvement identified'],
                        ['text' => 'Objectives set for the next 6 months'],
                        ['text' => "Manager's comments"],
                    ]],
                ],
            ],
            [
                'titre' => "Entretien de fin de contrat (Exit Interview)",
                'description' => "Questionnaire de sortie pour recueillir le feedback du collaborateur qui quitte l'entreprise.",
                'type' => 'custom',
                'declencheur' => 'manuel',
                'actif' => true,
                'questions' => [
                    ['text' => "Comment evaluez-vous votre experience globale dans l'entreprise ? (1-5)", 'type' => 'rating'],
                    ['text' => "Comment evaluez-vous la relation avec votre manager direct ? (1-5)", 'type' => 'rating'],
                    ['text' => "Comment evaluez-vous l'ambiance et la culture d'entreprise ? (1-5)", 'type' => 'rating'],
                    ['text' => "Comment evaluez-vous les opportunites de developpement professionnel ? (1-5)", 'type' => 'rating'],
                    ['text' => "Comment evaluez-vous l'equilibre vie professionnelle / vie personnelle ? (1-5)", 'type' => 'rating'],
                    ['text' => "Comment evaluez-vous la remuneration et les avantages ? (1-5)", 'type' => 'rating'],
                    ['text' => "Raison principale du depart", 'type' => 'choice', 'options' => ['Nouvelle opportunite professionnelle', 'Remuneration', 'Evolution de carriere limitee', 'Management', 'Conditions de travail', 'Demenagement / raisons personnelles', 'Fin de contrat / mission', 'Autre']],
                    ['text' => "Recommanderiez-vous cette entreprise comme employeur ? (0-10)", 'type' => 'nps'],
                    ['text' => "Qu'avez-vous le plus apprecie durant votre passage dans l'entreprise ?", 'type' => 'text'],
                    ['text' => "Qu'est-ce qui aurait pu vous retenir ?", 'type' => 'text'],
                    ['text' => "Quelles suggestions feriez-vous pour ameliorer l'experience des collaborateurs ?", 'type' => 'text'],
                    ['text' => "Seriez-vous ouvert(e) a une future collaboration avec l'entreprise ?", 'type' => 'choice', 'options' => ['Oui, certainement', 'Peut-etre', 'Non']],
                ],
                'translations' => [
                    'titre' => ['en' => 'Exit Interview'],
                    'description' => ['en' => 'Exit questionnaire to gather feedback from departing employees.'],
                    'questions' => ['en' => [
                        ['text' => 'How would you rate your overall experience at the company? (1-5)'],
                        ['text' => 'How would you rate the relationship with your direct manager? (1-5)'],
                        ['text' => 'How would you rate the company culture and atmosphere? (1-5)'],
                        ['text' => 'How would you rate professional development opportunities? (1-5)'],
                        ['text' => 'How would you rate the work-life balance? (1-5)'],
                        ['text' => 'How would you rate compensation and benefits? (1-5)'],
                        ['text' => 'Main reason for leaving', 'options' => ['New career opportunity', 'Compensation', 'Limited career growth', 'Management', 'Working conditions', 'Relocation / personal reasons', 'End of contract / mission', 'Other']],
                        ['text' => 'Would you recommend this company as an employer? (0-10)'],
                        ['text' => 'What did you appreciate most during your time at the company?'],
                        ['text' => 'What could have made you stay?'],
                        ['text' => "What suggestions would you make to improve the employee experience?"],
                        ['text' => 'Would you be open to future collaboration with the company?', 'options' => ['Yes, definitely', 'Maybe', 'No']],
                    ]],
                ],
            ],
            [
                'titre' => "Rapport d'étonnement (1 mois)",
                'description' => "Formulaire structuré que le nouvel employé remplit après 1 mois pour partager son regard neuf sur l'entreprise.",
                'type' => 'custom',
                'declencheur' => 'date_specifique',
                'actif' => true,
                'questions' => [
                    ['text' => "Qu'est-ce qui vous a le plus surpris positivement depuis votre arrivée ?", 'type' => 'text'],
                    ['text' => "Qu'est-ce qui vous a le plus surpris négativement ou déçu ?", 'type' => 'text'],
                    ['text' => "Le poste correspond-il à ce qui vous a été présenté lors du recrutement ? (1-5)", 'type' => 'rating'],
                    ['text' => "Comment évaluez-vous la qualité de votre accueil le premier jour ? (1-5)", 'type' => 'rating'],
                    ['text' => "Votre poste de travail et vos outils étaient-ils prêts à votre arrivée ? (1-5)", 'type' => 'rating'],
                    ['text' => "Comment évaluez-vous l'accompagnement de votre manager ? (1-5)", 'type' => 'rating'],
                    ['text' => "Vous sentez-vous intégré(e) dans votre équipe ? (1-5)", 'type' => 'rating'],
                    ['text' => "Les formations et ressources mises à disposition sont-elles suffisantes ? (1-5)", 'type' => 'rating'],
                    ['text' => "Avez-vous une visibilité claire sur vos objectifs et vos responsabilités ?", 'type' => 'choice', 'options' => ['Oui, très clair', 'Partiellement', 'Non, pas encore']],
                    ['text' => "Si vous pouviez changer une chose dans le processus d'intégration, ce serait :", 'type' => 'text'],
                    ['text' => "Recommanderiez-vous cette entreprise comme employeur ? (0-10)", 'type' => 'nps'],
                    ['text' => "Commentaire libre", 'type' => 'text'],
                ],
                'translations' => [
                    'titre' => ['en' => 'Newcomer Feedback Report (1 month)'],
                    'description' => ['en' => 'Structured form that new employees fill after 1 month to share their fresh perspective on the company.'],
                    'questions' => ['en' => [
                        ['text' => 'What surprised you most positively since your arrival?'],
                        ['text' => 'What surprised you most negatively or disappointed you?'],
                        ['text' => 'Does the position match what was presented during recruitment? (1-5)'],
                        ['text' => 'How would you rate the quality of your welcome on day one? (1-5)'],
                        ['text' => 'Were your workstation and tools ready when you arrived? (1-5)'],
                        ['text' => "How would you rate your manager's support? (1-5)"],
                        ['text' => 'Do you feel integrated into your team? (1-5)'],
                        ['text' => 'Are the training and resources provided sufficient? (1-5)'],
                        ['text' => 'Do you have clear visibility on your objectives and responsibilities?', 'options' => ['Yes, very clear', 'Partially', 'Not yet']],
                        ['text' => 'If you could change one thing about the onboarding process, it would be:'],
                        ['text' => 'Would you recommend this company as an employer? (0-10)'],
                        ['text' => 'Any other comments'],
                    ]],
                ],
            ],
        ];

        foreach ($surveys as $s) {
            \App\Models\NpsSurvey::updateOrCreate(['titre' => $s['titre']], $s);
        }

        // ── 20. Assign managers based on department ────────────
        $allCollabs = \App\Models\Collaborateur::all();
        if ($allCollabs->count() >= 2) {
            $departments = $allCollabs->groupBy('departement');
            $hrManagerIds = [];

            foreach ($departments as $dept => $collabs) {
                if ($collabs->count() < 2) continue;
                $manager = $collabs->first();
                $hrManagerIds[] = $manager->id;
                foreach ($collabs->skip(1) as $collab) {
                    $collab->update(['manager_id' => $manager->id]);
                }
            }

            // Assign HR managers (first department manager becomes HR manager for everyone)
            if (count($hrManagerIds) >= 1) {
                $primaryHR = $hrManagerIds[0];
                \App\Models\Collaborateur::whereNull('hr_manager_id')->update(['hr_manager_id' => $primaryHR]);
            }
        }

        // ── 21. Buddy Pairs ────────────────────────────────────
        if (\App\Models\BuddyPair::count() === 0) {
            $collabs = \App\Models\Collaborateur::take(6)->get();
            if ($collabs->count() >= 4) {
                \App\Models\BuddyPair::create([
                    'newcomer_id' => $collabs[0]->id,
                    'buddy_id' => $collabs[2]->id,
                    'status' => 'active',
                    'checklist' => [true, true, true, true, false, false, false, false],
                    'notes' => [
                        ['text' => 'Très bonne intégration, motivé et curieux', 'date' => now()->subDays(10)->toISOString()],
                        ['text' => 'A posé beaucoup de questions pertinentes sur les process', 'date' => now()->subDays(5)->toISOString()],
                    ],
                ]);
                \App\Models\BuddyPair::create([
                    'newcomer_id' => $collabs[1]->id,
                    'buddy_id' => $collabs[3]->id,
                    'status' => 'active',
                    'checklist' => [true, true, true, true, true, true, true, false],
                    'notes' => [
                        ['text' => 'Bonne adaptation, autonome rapidement', 'date' => now()->subDays(30)->toISOString()],
                        ['text' => 'RAS, tout se passe bien', 'date' => now()->subDays(15)->toISOString()],
                    ],
                ]);
                if ($collabs->count() >= 6) {
                    \App\Models\BuddyPair::create([
                        'newcomer_id' => $collabs[4]->id,
                        'buddy_id' => $collabs[2]->id,
                        'status' => 'completed',
                        'checklist' => [true, true, true, true, true, true, true, true],
                        'notes' => [
                            ['text' => 'Excellent accompagnement', 'date' => now()->subDays(60)->toISOString()],
                            ['text' => 'Intégration réussie, autonome', 'date' => now()->subDays(45)->toISOString()],
                        ],
                        'rating' => 4.5,
                        'feedback_comment' => 'Super parrain, toujours disponible et de bon conseil.',
                        'completed_at' => now()->subDays(30),
                    ]);
                }
            }
        }

        // ── 23. Enrich collaborateurs with full profile data ─────
        $collabs = \App\Models\Collaborateur::all();
        if ($collabs->isNotEmpty()) {
            $statuses = ['en_cours', 'en_cours', 'en_retard', 'termine', 'en_cours', 'en_retard'];
            $progressions = [15, 45, 80, 100, 0, 25];
            $enrichData = [
                ['civilite' => 'Mme', 'date_naissance' => '1992-03-15', 'nationalite' => 'Portugaise', 'telephone' => '+41 78 123 45 67', 'adresse' => 'Rue du Marché 12', 'ville' => 'Genève', 'code_postal' => '1204', 'pays' => 'Suisse', 'iban' => 'CH93 0076 2011 6238 5295 7', 'numero_avs' => '756.1234.5678.90', 'type_contrat' => 'CDI', 'salaire_brut' => '95000', 'devise' => 'CHF', 'taux_activite' => '100', 'periode_essai' => '3 mois', 'matricule' => 'ILZ-2026-001', 'job_title' => 'Chef de Projet', 'job_family' => 'Management', 'job_code' => 'PM-01', 'job_level' => 'Senior', 'employment_type' => 'CDI', 'motif_embauche' => 'Création de poste', 'position_title' => 'Chef de Projet Digital', 'position_code' => 'POS-PM-001', 'business_unit' => 'Digital', 'division' => 'Consulting', 'cost_center' => 'CC-100', 'location_code' => 'GVA-01', 'work_schedule' => 'Temps plein', 'fte' => '1.0'],
                ['civilite' => 'M.', 'date_naissance' => '1988-07-22', 'nationalite' => 'Française', 'telephone' => '+33 6 12 34 56 78', 'adresse' => '15 Avenue des Champs', 'ville' => 'Paris', 'code_postal' => '75008', 'pays' => 'France', 'iban' => 'FR76 3000 6000 0112 3456 7890 189', 'numero_avs' => '', 'type_contrat' => 'CDI', 'salaire_brut' => '52000', 'devise' => 'EUR', 'taux_activite' => '100', 'periode_essai' => '4 mois', 'matricule' => 'ILZ-2026-002', 'job_title' => 'Développeur Full Stack', 'job_family' => 'Engineering', 'job_code' => 'DEV-02', 'job_level' => 'Confirmé', 'employment_type' => 'CDI', 'motif_embauche' => 'Remplacement', 'position_title' => 'Développeur Full Stack', 'position_code' => 'POS-DEV-002', 'business_unit' => 'Tech', 'division' => 'R&D', 'cost_center' => 'CC-200', 'location_code' => 'PAR-01', 'work_schedule' => 'Horaires flexibles', 'fte' => '1.0'],
                ['civilite' => 'Mme', 'date_naissance' => '1995-11-08', 'nationalite' => 'Française', 'telephone' => '+33 7 98 76 54 32', 'adresse' => '8 Rue de la République', 'ville' => 'Lyon', 'code_postal' => '69002', 'pays' => 'France', 'iban' => 'FR76 1234 5000 0112 3456 7890 123', 'numero_avs' => '', 'type_contrat' => 'CDI', 'salaire_brut' => '45000', 'devise' => 'EUR', 'taux_activite' => '100', 'periode_essai' => '3 mois', 'matricule' => 'ILZ-2026-003', 'job_title' => 'UX Designer', 'job_family' => 'Design', 'job_code' => 'UX-01', 'job_level' => 'Junior', 'employment_type' => 'CDI', 'motif_embauche' => 'Création de poste', 'position_title' => 'UX/UI Designer', 'position_code' => 'POS-UX-003', 'business_unit' => 'Produit', 'division' => 'Design', 'cost_center' => 'CC-300', 'location_code' => 'LYO-01', 'work_schedule' => 'Temps plein', 'fte' => '1.0'],
                ['civilite' => 'M.', 'date_naissance' => '1990-01-30', 'nationalite' => 'Suisse', 'telephone' => '+41 79 876 54 32', 'adresse' => 'Chemin des Vignes 5', 'ville' => 'Genève', 'code_postal' => '1209', 'pays' => 'Suisse', 'iban' => 'CH93 0076 2011 6238 5295 8', 'numero_avs' => '756.9876.5432.10', 'type_contrat' => 'CDI', 'salaire_brut' => '88000', 'devise' => 'CHF', 'taux_activite' => '100', 'periode_essai' => '3 mois', 'matricule' => 'ILZ-2026-004', 'job_title' => 'Data Analyst', 'job_family' => 'Data', 'job_code' => 'DA-01', 'job_level' => 'Confirmé', 'employment_type' => 'CDI', 'motif_embauche' => 'Remplacement', 'position_title' => 'Data Analyst Senior', 'position_code' => 'POS-DA-004', 'business_unit' => 'Data', 'division' => 'Analytics', 'cost_center' => 'CC-400', 'location_code' => 'GVA-01', 'work_schedule' => 'Temps plein', 'fte' => '1.0'],
                ['civilite' => 'Mme', 'date_naissance' => '1993-05-18', 'nationalite' => 'Allemande', 'telephone' => '+41 76 543 21 09', 'adresse' => 'Bahnhofstrasse 42', 'ville' => 'Lausanne', 'code_postal' => '1003', 'pays' => 'Suisse', 'iban' => 'CH93 0076 2011 6238 5295 9', 'numero_avs' => '756.5555.1234.56', 'type_contrat' => 'CDD', 'salaire_brut' => '72000', 'devise' => 'CHF', 'taux_activite' => '80', 'periode_essai' => '1 mois', 'matricule' => 'ILZ-2026-005', 'job_title' => 'Consultante', 'job_family' => 'Consulting', 'job_code' => 'CON-01', 'job_level' => 'Senior', 'employment_type' => 'CDD', 'motif_embauche' => "Surcroît d'activité", 'date_fin_contrat' => '2026-12-31', 'position_title' => 'Consultante Senior', 'position_code' => 'POS-CON-005', 'business_unit' => 'Consulting', 'division' => 'Strategy', 'cost_center' => 'CC-500', 'location_code' => 'LAU-01', 'work_schedule' => 'Temps partiel', 'fte' => '0.8'],
                ['civilite' => 'Mme', 'date_naissance' => '1991-09-25', 'nationalite' => 'Française', 'telephone' => '+41 78 111 22 33', 'adresse' => 'Place du Molard 3', 'ville' => 'Genève', 'code_postal' => '1204', 'pays' => 'Suisse', 'iban' => 'CH93 0076 2011 6238 5296 0', 'numero_avs' => '756.3333.4444.55', 'type_contrat' => 'CDI', 'salaire_brut' => '85000', 'devise' => 'CHF', 'taux_activite' => '100', 'periode_essai' => '3 mois', 'matricule' => 'ILZ-2026-006', 'job_title' => 'Développeuse', 'job_family' => 'Engineering', 'job_code' => 'DEV-03', 'job_level' => 'Confirmé', 'employment_type' => 'CDI', 'motif_embauche' => 'Création de poste', 'position_title' => 'Développeuse Backend', 'position_code' => 'POS-DEV-006', 'business_unit' => 'Tech', 'division' => 'R&D', 'cost_center' => 'CC-200', 'location_code' => 'GVA-01', 'work_schedule' => 'Horaires flexibles', 'fte' => '1.0'],
            ];
            // Assign specific parcours to collaborateurs (varied categories)
            $parcoursOnboarding = \App\Models\Parcours::where('nom', 'Onboarding Standard')->first();
            $parcoursOffboarding = \App\Models\Parcours::where('nom', 'Départ standard')->first();
            $parcoursCrossboarding = \App\Models\Parcours::where('nom', 'Mobilité interne standard')->first();
            $parcoursReboarding = \App\Models\Parcours::where('nom', 'Retour congé maternité/parental')->first();
            $parcoursAssign = [
                $parcoursOnboarding,   // Nadia — onboarding en cours
                $parcoursOnboarding,   // Antoine — onboarding en cours
                $parcoursCrossboarding, // Inès — mobilité interne
                $parcoursOnboarding,   // Youssef — onboarding terminé
                $parcoursOffboarding,  // Clara — offboarding
                $parcoursReboarding,   // Marie — reboarding
            ];
            foreach ($collabs as $i => $c) {
                $idx = $i % count($statuses);
                $prog = $progressions[$idx];
                $extra = $enrichData[$i % count($enrichData)] ?? [];
                $assignedParcours = $parcoursAssign[$i] ?? $parcoursOnboarding;
                $c->update(array_merge([
                    'status' => $statuses[$idx],
                    'progression' => $prog,
                    'actions_completes' => intval(($prog / 100) * 10),
                    'actions_total' => 10,
                    'docs_valides' => intval(($prog / 100) * 5),
                    'docs_total' => 5,
                    'parcours_id' => $assignedParcours?->id,
                    'phase' => $prog >= 100 ? '3 premiers mois' : ($prog >= 50 ? 'Première semaine' : ($prog > 0 ? 'Avant le premier jour' : 'Avant date d\'arrivée')),
                ], $extra));
            }
        }

        // ── 24. Document submissions per collaborateur ────────
        $docCollabs = \App\Models\Collaborateur::take(6)->get();
        $docCategories = \App\Models\DocumentCategorie::all();
        if ($docCollabs->isNotEmpty() && $docCategories->isNotEmpty()) {
            $docStatuses = ['valide', 'soumis', 'manquant', 'refuse', 'valide', 'soumis'];
            $docNames = [
                "Pièce d'identité", 'RIB / IBAN', 'Attestation sécurité sociale',
                'Certificat de travail', 'Justificatif de domicile', 'Carte vitale',
            ];
            foreach ($docCollabs as $ci => $collab) {
                foreach (array_slice($docNames, 0, 3) as $di => $docNom) {
                    $statusIdx = ($ci + $di) % count($docStatuses);
                    $status = $docStatuses[$statusIdx];
                    \App\Models\Document::firstOrCreate(
                        ['nom' => $docNom . ' — ' . $collab->prenom, 'collaborateur_id' => $collab->id],
                        [
                            'nom' => $docNom,
                            'obligatoire' => $di < 2,
                            'type' => 'upload',
                            'categorie_id' => $docCategories->first()->id,
                            'status' => $status,
                            'collaborateur_id' => $collab->id,
                            'validated_at' => $status === 'valide' ? now()->subDays(rand(1, 20)) : null,
                            'refuse_motif' => $status === 'refuse' ? 'Document illisible, merci de renvoyer une copie nette.' : null,
                            'notes' => $status === 'soumis' ? 'En attente de validation par le RH.' : null,
                        ]
                    );
                }
            }
        }

        // ── 25. Action completions per collaborateur (from their parcours) ──
        $actionCollabs = \App\Models\Collaborateur::take(6)->get();
        if ($actionCollabs->isNotEmpty()) {
            $actionStatuses = ['termine', 'termine', 'en_cours', 'a_faire', 'termine', 'en_cours'];
            foreach ($actionCollabs as $ci => $collab) {
                // Get actions for this collab's parcours
                $collabParcours = $collab->parcours;
                $actions = $collabParcours ? \App\Models\Action::where('parcours_id', $collabParcours->id)->take(5)->get() : \App\Models\Action::take(5)->get();
                if ($actions->isEmpty()) $actions = \App\Models\Action::take(5)->get();
                foreach ($actions as $ai => $action) {
                    $statusIdx = ($ci + $ai) % count($actionStatuses);
                    $status = $actionStatuses[$statusIdx];
                    \App\Models\CollaborateurAction::firstOrCreate(
                        ['collaborateur_id' => $collab->id, 'action_id' => $action->id],
                        [
                            'status' => $status,
                            'started_at' => now()->subDays(rand(5, 40)),
                            'completed_at' => $status === 'termine' ? now()->subDays(rand(1, 10)) : null,
                            'note' => $status === 'termine' ? 'Terminé avec succès.' : null,
                        ]
                    );
                }
            }
        }

        // ── 26. NPS responses ─────────────────────────────────
        $npsSurveys = \App\Models\NpsSurvey::all();
        $npsCollabs = \App\Models\Collaborateur::take(6)->get();
        if ($npsSurveys->isNotEmpty() && $npsCollabs->isNotEmpty()) {
            $comments = [
                'Très satisfait de mon intégration, équipe accueillante.',
                'Bon processus, quelques améliorations possibles sur la documentation.',
                'Excellent accompagnement de mon manager.',
                'Un peu perdu les premiers jours, mais vite rattrapé grâce au buddy.',
                'Outils bien préparés, formation claire et complète.',
                'Je recommande vivement, expérience top !',
            ];
            foreach ($npsSurveys->take(2) as $survey) {
                foreach ($npsCollabs->take(4) as $ci => $collab) {
                    \App\Models\NpsResponse::firstOrCreate(
                        ['survey_id' => $survey->id, 'collaborateur_id' => $collab->id],
                        [
                            'score' => rand(6, 10),
                            'rating' => rand(3, 5),
                            'answers' => [],
                            'comment' => $comments[$ci % count($comments)],
                            'completed_at' => now()->subDays(rand(1, 30)),
                            'token' => \Illuminate\Support\Str::random(32),
                        ]
                    );
                }
            }
        }

        // ── 27. Demo data loaded flag ─────────────────────────
        \App\Models\CompanySetting::set('demo_data_loaded', 'true');

        // ── 22. Roles par defaut ────────────────────────────────
        // Clean up legacy Spatie-only roles (admin, onboardee) that don't have custom columns
        Role::whereIn('name', ['admin', 'onboardee'])->where(function ($q) {
            $q->whereNull('slug')->orWhere('slug', '');
        })->delete();

        $allAdmin = array_fill_keys([
            'parcours', 'collaborateurs', 'documents', 'equipements', 'nps',
            'workflows', 'company_page', 'integrations', 'settings', 'reports',
            'cooptation', 'contrats', 'signatures', 'gamification',
        ], 'admin');

        Role::updateOrCreate(['name' => 'super_admin'], [
            'name' => 'super_admin', 'guard_name' => 'web',
            'nom' => 'Super Admin',
            'slug' => 'super_admin',
            'description' => 'Acces complet a toutes les fonctionnalites de la plateforme.',
            'couleur' => '#E53935',
            'is_system' => true,
            'is_default' => false,
            'scope_type' => 'global',
            'permissions' => $allAdmin,
            'ordre' => 0,
            'actif' => true,
        ]);

        Role::updateOrCreate(['name' => 'admin_rh'], [
            'name' => 'admin_rh', 'guard_name' => 'web',
            'nom' => 'Admin RH',
            'slug' => 'admin_rh',
            'description' => 'Administration des ressources humaines avec acces etendu.',
            'couleur' => '#C2185B',
            'is_system' => true,
            'is_default' => false,
            'scope_type' => 'global',
            'permissions' => array_merge($allAdmin, [
                'integrations' => 'view',
                'settings' => 'view',
            ]),
            'ordre' => 1,
            'actif' => true,
        ]);

        Role::updateOrCreate(['name' => 'manager'], [
            'name' => 'manager', 'guard_name' => 'web',
            'nom' => 'Manager',
            'slug' => 'manager',
            'description' => 'Gestion des equipes et suivi des collaborateurs.',
            'couleur' => '#1A73E8',
            'is_system' => true,
            'is_default' => false,
            'scope_type' => 'equipe',
            'permissions' => [
                'parcours' => 'view',
                'collaborateurs' => 'view',
                'documents' => 'view',
                'equipements' => 'none',
                'nps' => 'view',
                'workflows' => 'none',
                'company_page' => 'none',
                'integrations' => 'none',
                'settings' => 'none',
                'reports' => 'view',
                'cooptation' => 'none',
                'contrats' => 'none',
                'signatures' => 'none',
                'gamification' => 'view',
            ],
            'ordre' => 2,
            'actif' => true,
        ]);

        Role::updateOrCreate(['name' => 'hrbp'], [
            'name' => 'hrbp', 'guard_name' => 'web',
            'nom' => 'HRBP',
            'slug' => 'hrbp',
            'description' => 'HR Business Partner avec droits etendus sur les modules RH.',
            'couleur' => '#7B5EA7',
            'is_system' => false,
            'is_default' => false,
            'scope_type' => 'global',
            'permissions' => [
                'parcours' => 'edit',
                'collaborateurs' => 'edit',
                'documents' => 'edit',
                'equipements' => 'view',
                'nps' => 'edit',
                'workflows' => 'edit',
                'company_page' => 'view',
                'integrations' => 'none',
                'settings' => 'none',
                'reports' => 'edit',
                'cooptation' => 'edit',
                'contrats' => 'edit',
                'signatures' => 'edit',
                'gamification' => 'edit',
            ],
            'ordre' => 3,
            'actif' => true,
        ]);

        Role::updateOrCreate(['name' => 'collaborateur'], [
            'name' => 'collaborateur', 'guard_name' => 'web',
            'nom' => 'Collaborateur',
            'slug' => 'collaborateur',
            'description' => 'Role par defaut pour les nouveaux utilisateurs.',
            'couleur' => '#4CAF50',
            'is_system' => true,
            'is_default' => true,
            'scope_type' => 'global',
            'permissions' => [
                'parcours' => 'none',
                'collaborateurs' => 'view',
                'documents' => 'none',
                'equipements' => 'none',
                'nps' => 'none',
                'workflows' => 'none',
                'company_page' => 'view',
                'integrations' => 'none',
                'settings' => 'none',
                'reports' => 'none',
                'cooptation' => 'none',
                'contrats' => 'none',
                'signatures' => 'none',
                'gamification' => 'view',
            ],
            'ordre' => 4,
            'actif' => true,
        ]);

        Role::updateOrCreate(['name' => 'auditeur'], [
            'name' => 'auditeur', 'guard_name' => 'web',
            'nom' => 'Auditeur',
            'slug' => 'auditeur',
            'description' => 'Acces en lecture seule a l\'ensemble de la plateforme.',
            'couleur' => '#78909C',
            'is_system' => false,
            'is_default' => false,
            'scope_type' => 'global',
            'permissions' => array_fill_keys([
                'parcours', 'collaborateurs', 'documents', 'equipements', 'nps',
                'workflows', 'company_page', 'integrations', 'settings', 'reports',
                'cooptation', 'contrats', 'signatures', 'gamification',
            ], 'view'),
            'ordre' => 5,
            'actif' => true,
        ]);
    }
}
