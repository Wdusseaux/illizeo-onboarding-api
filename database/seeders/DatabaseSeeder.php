<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ─── Plans ──────────────────────────────────────────────
        $starter = Plan::create([
            'nom' => 'Starter',
            'slug' => 'starter',
            'prix_eur_mensuel' => 5.00,
            'prix_chf_mensuel' => 5.50,
            'min_mensuel_eur' => 200,
            'min_mensuel_chf' => 220,
            'max_collaborateurs' => 50,
            'max_admins' => 3,
            'max_parcours' => 3,
            'max_integrations' => 2,
            'max_workflows' => 5,
            'ordre' => 1,
        ]);

        foreach (['onboarding', 'offboarding'] as $module) {
            $starter->modules()->create(['module' => $module, 'actif' => true]);
        }

        $business = Plan::create([
            'nom' => 'Business',
            'slug' => 'business',
            'prix_eur_mensuel' => 9.00,
            'prix_chf_mensuel' => 9.50,
            'min_mensuel_eur' => 500,
            'min_mensuel_chf' => 550,
            'max_collaborateurs' => 500,
            'max_admins' => 10,
            'max_parcours' => null,
            'max_integrations' => 5,
            'max_workflows' => null,
            'populaire' => true,
            'ordre' => 2,
        ]);

        foreach (['onboarding', 'offboarding', 'crossboarding', 'cooptation', 'nps', 'signature'] as $module) {
            $business->modules()->create(['module' => $module, 'actif' => true]);
        }

        $enterprise = Plan::create([
            'nom' => 'Enterprise',
            'slug' => 'enterprise',
            'prix_eur_mensuel' => 12.00,
            'prix_chf_mensuel' => 13.00,
            'min_mensuel_eur' => 3000,
            'min_mensuel_chf' => 3300,
            'max_collaborateurs' => null,
            'max_admins' => null,
            'max_parcours' => null,
            'max_integrations' => null,
            'max_workflows' => null,
            'ordre' => 3,
        ]);

        $allModules = [
            'onboarding', 'offboarding', 'crossboarding', 'cooptation',
            'nps', 'signature', 'sso', 'provisioning', 'api',
            'white_label', 'gamification',
        ];

        foreach ($allModules as $module) {
            $enterprise->modules()->create(['module' => $module, 'actif' => true]);
        }

        // ─── Cooptation add-on plan ─────────────────────────────
        $cooptation = Plan::create([
            'nom' => 'Cooptation',
            'slug' => 'cooptation',
            'description' => 'Programme de cooptation et parrainage',
            'prix_eur_mensuel' => 3.60,
            'prix_chf_mensuel' => 3.99,
            'min_mensuel_eur' => 90,
            'min_mensuel_chf' => 99.75,
            'max_collaborateurs' => null,
            'max_admins' => null,
            'max_parcours' => null,
            'max_integrations' => null,
            'max_workflows' => null,
            'ordre' => 10,
        ]);
        $cooptation->modules()->create(['module' => 'cooptation', 'actif' => true]);

        // ─── Demo tenant ────────────────────────────────────────
        $tenant = \App\Models\Tenant::create([
            'id' => 'illizeo',
            'nom' => 'Illizeo',
            'slug' => 'illizeo',
            'plan' => 'enterprise',
            'plan_id' => $enterprise->id,
            'actif' => true,
        ]);

        $tenant->domains()->create(['domain' => 'localhost']);
    }
}
