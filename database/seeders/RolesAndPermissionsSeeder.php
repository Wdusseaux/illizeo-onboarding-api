<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Permissions ─────────────────────────────────────
        $permissions = [
            // Collaborateurs
            'collaborateurs.view',
            'collaborateurs.create',
            'collaborateurs.edit',
            'collaborateurs.delete',

            // Parcours
            'parcours.view',
            'parcours.create',
            'parcours.edit',
            'parcours.delete',

            // Actions
            'actions.view',
            'actions.create',
            'actions.edit',
            'actions.delete',
            'actions.complete',

            // Phases
            'phases.view',
            'phases.create',
            'phases.edit',
            'phases.delete',

            // Documents
            'documents.view',
            'documents.upload',
            'documents.validate',
            'documents.delete',

            // Groupes
            'groupes.view',
            'groupes.create',
            'groupes.edit',
            'groupes.delete',

            // Workflows
            'workflows.view',
            'workflows.create',
            'workflows.edit',
            'workflows.delete',

            // Email templates
            'templates.view',
            'templates.create',
            'templates.edit',
            'templates.delete',

            // Contrats
            'contrats.view',
            'contrats.create',
            'contrats.edit',
            'contrats.delete',

            // Notifications
            'notifications.view',
            'notifications.manage',

            // Entreprise
            'entreprise.view',
            'entreprise.manage',

            // Intégrations
            'integrations.view',
            'integrations.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // ── Roles ───────────────────────────────────────────

        // Super Admin — all permissions (via Gate::before in AuthServiceProvider)
        Role::firstOrCreate(['name' => 'super_admin']);

        // Admin — full tenant access
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions);

        // Admin RH — operational HR management
        $adminRH = Role::firstOrCreate(['name' => 'admin_rh']);
        $adminRH->syncPermissions([
            'collaborateurs.view', 'collaborateurs.create', 'collaborateurs.edit', 'collaborateurs.delete',
            'parcours.view', 'parcours.create', 'parcours.edit', 'parcours.delete',
            'actions.view', 'actions.create', 'actions.edit', 'actions.delete',
            'phases.view', 'phases.create', 'phases.edit', 'phases.delete',
            'documents.view', 'documents.upload', 'documents.validate', 'documents.delete',
            'groupes.view', 'groupes.create', 'groupes.edit', 'groupes.delete',
            'workflows.view', 'workflows.create', 'workflows.edit', 'workflows.delete',
            'templates.view', 'templates.create', 'templates.edit', 'templates.delete',
            'contrats.view', 'contrats.create', 'contrats.edit', 'contrats.delete',
            'notifications.view', 'notifications.manage',
            'entreprise.view', 'entreprise.manage',
            'integrations.view', 'integrations.manage',
        ]);

        // Manager — team view + validate
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $manager->syncPermissions([
            'collaborateurs.view',
            'parcours.view',
            'actions.view',
            'phases.view',
            'documents.view', 'documents.validate',
            'groupes.view',
            'entreprise.view',
        ]);

        // Onboardee — own parcours only
        $onboardee = Role::firstOrCreate(['name' => 'onboardee']);
        $onboardee->syncPermissions([
            'parcours.view',
            'actions.view', 'actions.complete',
            'documents.view', 'documents.upload',
            'entreprise.view',
        ]);
    }
}
