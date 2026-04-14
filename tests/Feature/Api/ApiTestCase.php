<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Run tenant migrations on the default (in-memory) SQLite connection.
     * Since tests use :memory: SQLite, we do not need real tenancy —
     * we just need the tables from the tenant migration path.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Run tenant migrations on the test database so all tenant tables exist.
        // The standard RefreshDatabase already ran central migrations (database/migrations/).
        // We also need the tenant-specific tables (database/migrations/tenant/).
        $tenantMigrationPath = database_path('migrations/tenant');
        if (is_dir($tenantMigrationPath)) {
            Artisan::call('migrate', [
                '--path' => $tenantMigrationPath,
                '--realpath' => true,
                '--no-interaction' => true,
            ]);
        }
    }

    /**
     * Override the default middleware so tenancy middleware is bypassed.
     * Routes require InitializeTenancyByRequestData which would fail
     * without a real tenant — we skip it since tests run on a single DB.
     */
    protected function withoutTenancyMiddleware(): static
    {
        return $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::class,
        ]);
    }

    // ─── User helpers ───────────────────────────────────────────

    /**
     * Create a super_admin custom role and assign it to a new user.
     */
    protected function createAdmin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $role = $this->getOrCreateSuperAdminRole();
        $user->customRoles()->attach($role->id, [
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ]);

        return $user->fresh();
    }

    /**
     * Create a regular user with a given custom role permissions map.
     * If no permissions provided, user gets an empty role (no access).
     */
    protected function createUser(array $attributes = [], array $permissions = []): User
    {
        $user = User::factory()->create($attributes);

        if (!empty($permissions)) {
            $role = Role::create([
                'nom' => 'Test Role',
                'slug' => 'test_role_' . $user->id,
                'name' => 'test_role_' . $user->id,
                'guard_name' => 'web',
                'permissions' => $permissions,
                'actif' => true,
            ]);

            $user->customRoles()->attach($role->id, [
                'assigned_by' => $user->id,
                'assigned_at' => now(),
            ]);
        }

        return $user->fresh();
    }

    /**
     * Create a user with specific module permissions and authenticate via Sanctum.
     */
    protected function createUserWithPermissions(array $permissions): User
    {
        $user = $this->createUser([], $permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Authenticate as an admin user (super_admin role) via Sanctum.
     */
    protected function actingAsAdmin(array $attributes = []): User
    {
        $user = $this->createAdmin($attributes);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Authenticate as a regular user via Sanctum.
     */
    protected function actingAsUser(array $attributes = [], array $permissions = []): User
    {
        $user = $this->createUser($attributes, $permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    // ─── Role helpers ───────────────────────────────────────────

    protected function getOrCreateSuperAdminRole(): Role
    {
        return Role::firstOrCreate(
            ['slug' => 'super_admin'],
            [
                'nom' => 'Super Admin',
                'name' => 'super_admin',
                'guard_name' => 'web',
                'is_system' => true,
                'actif' => true,
                'permissions' => [
                    'parcours' => 'admin',
                    'collaborateurs' => 'admin',
                    'documents' => 'admin',
                    'equipements' => 'admin',
                    'nps' => 'admin',
                    'workflows' => 'admin',
                    'company_page' => 'admin',
                    'integrations' => 'admin',
                    'settings' => 'admin',
                    'reports' => 'admin',
                    'cooptation' => 'admin',
                    'contrats' => 'admin',
                    'signatures' => 'admin',
                    'gamification' => 'admin',
                ],
            ]
        );
    }

    // ─── API request helpers ────────────────────────────────────

    /**
     * Make a JSON API call with tenancy middleware disabled.
     */
    protected function apiGet(string $uri, array $headers = [])
    {
        return $this->withoutTenancyMiddleware()->getJson("/api/v1{$uri}", $headers);
    }

    protected function apiPost(string $uri, array $data = [], array $headers = [])
    {
        return $this->withoutTenancyMiddleware()->postJson("/api/v1{$uri}", $data, $headers);
    }

    protected function apiPut(string $uri, array $data = [], array $headers = [])
    {
        return $this->withoutTenancyMiddleware()->putJson("/api/v1{$uri}", $data, $headers);
    }

    protected function apiPatch(string $uri, array $data = [], array $headers = [])
    {
        return $this->withoutTenancyMiddleware()->patchJson("/api/v1{$uri}", $data, $headers);
    }

    protected function apiDelete(string $uri, array $data = [], array $headers = [])
    {
        return $this->withoutTenancyMiddleware()->deleteJson("/api/v1{$uri}", $data, $headers);
    }
}
