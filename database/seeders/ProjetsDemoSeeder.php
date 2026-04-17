<?php

namespace Database\Seeders;

use App\Models\CommentaireTache;
use App\Models\Jalon;
use App\Models\LigneCout;
use App\Models\Projet;
use App\Models\SousProjet;
use App\Models\SousTache;
use App\Models\Tache;
use App\Models\TauxHoraire;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds 3 projets de démo qui reproduisent les données du mock React initial.
 * À lancer après que des utilisateurs existent dans la base tenant.
 *
 * Usage : php artisan db:seed --class=Database\\Seeders\\Tenant\\ProjetsDemoSeeder
 */
class ProjetsDemoSeeder extends Seeder
{
    public function run(): void
    {
        // On récupère 9 utilisateurs existants pour mapper aux ids "e1"..."e9" du mock.
        // Si moins de 9 users existent, on prend ce qu'on a et on cycle.
        $users = User::orderBy('id')->take(9)->get();

        if ($users->isEmpty()) {
            $this->command?->warn('ProjetsDemoSeeder : aucun utilisateur en base, seeder ignoré.');
            return;
        }

        // Helper pour résoudre un "user index" (1..9) en user_id réel
        $u = function (int $index) use ($users): ?int {
            $i = ($index - 1) % $users->count();
            return $users[$i]?->id;
        };

        // ═══════════════════════════════════════════════════════════════
        // PROJET 1 — Développement Illizeo v2 (interne, en heures)
        // ═══════════════════════════════════════════════════════════════
        $p1 = Projet::create([
            'nom' => 'Développement Illizeo v2',
            'code' => 'DEV-V2',
            'statut' => 'actif',
            'couleur' => '#3b82f6',
            'client_type' => 'internal',
            'client' => 'Interne',
            'date_debut' => '2025-01-15',
            'date_fin' => '2025-12-31',
            'description' => 'Refonte complète de la plateforme RH',
            'devise' => 'CHF',
            'est_facturable' => false,
            'type_budget' => 'hours',
            'valeur_budget' => 2000,
            'prix_vente' => 0,
        ]);
        $p1->membres()->attach([$u(1), $u(2), $u(3), $u(6), $u(7)]);

        $p1Sub1 = SousProjet::create(['projet_id' => $p1->id, 'nom' => 'Backend API', 'heures' => 320, 'est_facturable' => false]);
        $p1Sub2 = SousProjet::create(['projet_id' => $p1->id, 'nom' => 'Frontend React', 'heures' => 180, 'est_facturable' => false]);
        $p1Sub3 = SousProjet::create(['projet_id' => $p1->id, 'nom' => 'Infrastructure', 'heures' => 45, 'est_facturable' => false]);

        TauxHoraire::insert([
            ['projet_id' => $p1->id, 'role_libelle' => 'Chef de projet', 'taux' => 150, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p1->id, 'role_libelle' => 'Développeur', 'taux' => 120, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p1->id, 'role_libelle' => 'DevOps', 'taux' => 130, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p1->id, 'role_libelle' => 'QA', 'taux' => 110, 'created_at' => now(), 'updated_at' => now()],
        ]);

        LigneCout::insert([
            ['projet_id' => $p1->id, 'libelle' => 'Licences GitHub Team', 'montant' => 2400, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p1->id, 'libelle' => 'Hébergement Cloud', 'montant' => 3600, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p1->id, 'libelle' => 'CI/CD Tools', 'montant' => 800, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Tâche 1 : Migration BDD (done, lead = u1)
        $t1 = Tache::create([
            'projet_id' => $p1->id, 'sous_projet_id' => $p1Sub1->id,
            'titre' => 'Migration base de données', 'statut' => 'done',
            'priorite' => 'high', 'lead_id' => $u(1),
            'due_date' => '2025-03-01', 'tags' => ['Infra'],
        ]);
        SousTache::insert([
            ['tache_id' => $t1->id, 'titre' => 'Backup PostgreSQL', 'est_terminee' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tache_id' => $t1->id, 'titre' => 'Script migration v1→v2', 'est_terminee' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tache_id' => $t1->id, 'titre' => 'Validation données', 'est_terminee' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        CommentaireTache::create([
            'tache_id' => $t1->id, 'user_id' => $u(1),
            'contenu' => 'Migration terminée sans perte de données',
            'created_at' => '2025-03-01 14:30:00', 'updated_at' => '2025-03-01 14:30:00',
        ]);

        // Tâche 2 : API REST absences (in_progress, lead = u2, collab = u1)
        $t2 = Tache::create([
            'projet_id' => $p1->id, 'sous_projet_id' => $p1Sub1->id,
            'titre' => 'API REST absences', 'statut' => 'in_progress',
            'priorite' => 'urgent', 'lead_id' => $u(2),
            'due_date' => '2025-04-15', 'tags' => ['Feature'],
        ]);
        $t2->collaborateurs()->attach([$u(1)]);
        SousTache::insert([
            ['tache_id' => $t2->id, 'titre' => 'Endpoints CRUD', 'est_terminee' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tache_id' => $t2->id, 'titre' => 'Validation & middleware', 'est_terminee' => false, 'created_at' => now(), 'updated_at' => now()],
            ['tache_id' => $t2->id, 'titre' => 'Tests unitaires', 'est_terminee' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        CommentaireTache::create([
            'tache_id' => $t2->id, 'user_id' => $u(2),
            'contenu' => 'Endpoints CRUD OK, reste validation',
            'created_at' => '2025-03-20 10:00:00', 'updated_at' => '2025-03-20 10:00:00',
        ]);

        // Tâche 3 : Dashboard React (in_progress, lead = u3, collab = u1)
        $t3 = Tache::create([
            'projet_id' => $p1->id, 'sous_projet_id' => $p1Sub2->id,
            'titre' => 'Dashboard React', 'statut' => 'in_progress',
            'priorite' => 'normal', 'lead_id' => $u(3),
            'due_date' => '2025-05-01', 'tags' => ['Feature', 'Design'],
        ]);
        $t3->collaborateurs()->attach([$u(1)]);
        SousTache::insert([
            ['tache_id' => $t3->id, 'titre' => 'Layout + routing', 'est_terminee' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tache_id' => $t3->id, 'titre' => 'Composants KPI', 'est_terminee' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Tache::create([
            'projet_id' => $p1->id, 'sous_projet_id' => $p1Sub3->id,
            'titre' => 'CI/CD pipeline', 'statut' => 'todo',
            'priorite' => 'high', 'lead_id' => $u(6),
            'due_date' => '2025-06-01', 'tags' => ['Infra'],
        ]);

        Tache::create([
            'projet_id' => $p1->id, 'sous_projet_id' => null,
            'titre' => 'Tests E2E', 'statut' => 'todo',
            'priorite' => 'low', 'lead_id' => $u(7),
            'due_date' => '2025-07-01', 'tags' => [],
        ]);

        // ═══════════════════════════════════════════════════════════════
        // PROJET 2 — Onboarding Client Genève (externe, facturable)
        // ═══════════════════════════════════════════════════════════════
        $p2 = Projet::create([
            'nom' => 'Onboarding Client Genève',
            'code' => 'CLI-GVA',
            'statut' => 'actif',
            'couleur' => '#d4006e',
            'client_type' => 'external',
            'client' => 'Clinique du Lac SA',
            'contact_prenom' => 'Jean',
            'contact_nom' => 'Dupuis',
            'societe' => 'Clinique du Lac SA',
            'adresse_client' => '12 Quai du Mont-Blanc, 1201 Genève',
            'email_client' => 'j.dupuis@cliniquedulac.ch',
            'date_debut' => '2025-02-01',
            'date_fin' => '2025-06-30',
            'description' => 'Déploiement et paramétrage',
            'devise' => 'EUR',
            'est_facturable' => true,
            'type_budget' => 'cost',
            'valeur_budget' => 45000,
            'prix_vente' => 55000,
        ]);
        $p2->membres()->attach([$u(4), $u(5), $u(8)]);

        SousProjet::create(['projet_id' => $p2->id, 'nom' => 'Heures non affectées', 'heures' => 0, 'est_facturable' => false]);
        $p2Sub2 = SousProjet::create(['projet_id' => $p2->id, 'nom' => 'Paramétrage', 'heures' => 85, 'est_facturable' => true]);
        $p2Sub3 = SousProjet::create(['projet_id' => $p2->id, 'nom' => 'Formation', 'heures' => 20, 'est_facturable' => true]);

        TauxHoraire::insert([
            ['projet_id' => $p2->id, 'role_libelle' => 'Consultant', 'taux' => 180, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p2->id, 'role_libelle' => 'Data Analyst', 'taux' => 140, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p2->id, 'role_libelle' => 'Project Manager', 'taux' => 160, 'created_at' => now(), 'updated_at' => now()],
        ]);

        LigneCout::insert([
            ['projet_id' => $p2->id, 'libelle' => 'Déplacement Genève', 'montant' => 1200, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p2->id, 'libelle' => 'Licence formation', 'montant' => 800, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Jalon::insert([
            ['projet_id' => $p2->id, 'libelle' => 'Signature contrat (30%)', 'montant' => 16500, 'date' => '2025-02-01', 'statut' => 'paid', 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p2->id, 'libelle' => 'Livraison paramétrage (40%)', 'montant' => 22000, 'date' => '2025-04-15', 'statut' => 'sent', 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => $p2->id, 'libelle' => 'Go-live final (30%)', 'montant' => 16500, 'date' => '2025-06-30', 'statut' => 'planned', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Tache::create([
            'projet_id' => $p2->id, 'sous_projet_id' => $p2Sub2->id,
            'titre' => "Config types d'absence", 'statut' => 'done',
            'priorite' => 'normal', 'lead_id' => $u(4),
            'due_date' => '2025-03-15', 'tags' => [],
        ]);

        $t22 = Tache::create([
            'projet_id' => $p2->id, 'sous_projet_id' => $p2Sub2->id,
            'titre' => 'Import données employés', 'statut' => 'in_progress',
            'priorite' => 'high', 'lead_id' => $u(5),
            'due_date' => '2025-04-01', 'tags' => ['Infra'],
        ]);
        $t22->collaborateurs()->attach([$u(8)]);
        SousTache::insert([
            ['tache_id' => $t22->id, 'titre' => 'Extraction CSV', 'est_terminee' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tache_id' => $t22->id, 'titre' => 'Mapping champs', 'est_terminee' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Tache::create([
            'projet_id' => $p2->id, 'sous_projet_id' => $p2Sub3->id,
            'titre' => 'Formation managers', 'statut' => 'todo',
            'priorite' => 'normal', 'lead_id' => $u(8),
            'due_date' => '2025-05-15', 'tags' => ['Docs'],
        ]);

        // ═══════════════════════════════════════════════════════════════
        // PROJET 3 — Support Q1 2025 (archivé)
        // ═══════════════════════════════════════════════════════════════
        $p3 = Projet::create([
            'nom' => 'Support Q1 2025',
            'code' => 'SUP-Q1',
            'statut' => 'archive',
            'couleur' => '#10b981',
            'client_type' => 'internal',
            'client' => 'Divers',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-03-31',
            'description' => 'Tickets support Q1',
            'devise' => 'CHF',
            'est_facturable' => false,
            'type_budget' => 'none',
            'valeur_budget' => 0,
            'prix_vente' => 0,
        ]);
        $p3->membres()->attach([$u(9)]);

        $this->command?->info('ProjetsDemoSeeder : 3 projets de démo créés ✓');
    }
}
