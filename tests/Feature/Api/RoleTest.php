<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;

class RoleTest extends ApiTestCase
{
    private function createCustomRole(array $attrs = []): Role
    {
        static $counter = 0;
        $counter++;

        return Role::create(array_merge([
            'nom' => "Role Test {$counter}",
            'slug' => "role_test_{$counter}",
            'name' => "role_test_{$counter}",
            'guard_name' => 'web',
            'permissions' => ['parcours' => 'view'],
            'actif' => true,
        ], $attrs));
    }

    // ─── Index ──────────────────────────────────────────────────

    public function test_list_roles_as_admin(): void
    {
        $this->actingAsAdmin();
        $this->createCustomRole();

        $response = $this->apiGet('/roles');

        // At least the super_admin role + the one we created
        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, count($response->json()));
    }

    public function test_list_roles_includes_users_count(): void
    {
        $this->actingAsAdmin();
        $role = $this->createCustomRole();

        $response = $this->apiGet('/roles');

        $response->assertOk()
            ->assertJsonFragment(['users_count' => 0]);
    }

    public function test_list_roles_requires_settings_admin_permission(): void
    {
        $this->actingAsUser([], ['settings' => 'view']);

        $response = $this->apiGet('/roles');

        $response->assertStatus(403);
    }

    public function test_list_roles_unauthenticated(): void
    {
        $response = $this->apiGet('/roles');

        $response->assertStatus(401);
    }

    // ─── Store ──────────────────────────────────────────────────

    public function test_create_role_success(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/roles', [
            'nom' => 'Manager RH',
            'description' => 'Responsable RH avec acces complet',
            'couleur' => '#4CAF50',
            'permissions' => [
                'collaborateurs' => 'admin',
                'parcours' => 'edit',
                'documents' => 'view',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['nom' => 'Manager RH']);

        $this->assertDatabaseHas('roles', ['nom' => 'Manager RH']);
    }

    public function test_create_role_auto_generates_slug(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/roles', [
            'nom' => 'Chef Equipe',
            'permissions' => ['parcours' => 'view'],
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertNotEmpty($data['slug']);
    }

    public function test_create_role_validation_errors(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/roles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nom', 'permissions']);
    }

    // ─── Show ───────────────────────────────────────────────────

    public function test_show_role_with_users(): void
    {
        $admin = $this->actingAsAdmin();
        $role = $this->createCustomRole();
        $user = User::factory()->create();
        $role->users()->attach($user->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
        ]);

        $response = $this->apiGet("/roles/{$role->id}");

        $response->assertOk()
            ->assertJsonFragment(['users_count' => 1]);
    }

    // ─── Update ─────────────────────────────────────────────────

    public function test_update_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->createCustomRole();

        $response = $this->apiPut("/roles/{$role->id}", [
            'nom' => 'Updated Role',
            'permissions' => ['parcours' => 'admin'],
        ]);

        $response->assertOk()
            ->assertJsonFragment(['nom' => 'Updated Role']);
    }

    // ─── Delete ─────────────────────────────────────────────────

    public function test_delete_non_system_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->createCustomRole(['is_system' => false]);

        $response = $this->apiDelete("/roles/{$role->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Role supprime.']);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_cannot_delete_system_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->createCustomRole(['is_system' => true]);

        $response = $this->apiDelete("/roles/{$role->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Impossible de supprimer un role systeme.']);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    // ─── Assign user ────────────────────────────────────────────

    public function test_assign_user_to_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->createCustomRole();
        $user = User::factory()->create();

        $response = $this->apiPost("/roles/{$role->id}/assign", [
            'user_id' => $user->id,
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Role assigne.']);

        $this->assertDatabaseHas('role_user', [
            'role_id' => $role->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_assign_duplicate_user_to_role(): void
    {
        $admin = $this->actingAsAdmin();
        $role = $this->createCustomRole();
        $user = User::factory()->create();

        // Assign once
        $role->users()->attach($user->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
        ]);

        // Assign again
        $response = $this->apiPost("/roles/{$role->id}/assign", [
            'user_id' => $user->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_assign_nonexistent_user(): void
    {
        $this->actingAsAdmin();
        $role = $this->createCustomRole();

        $response = $this->apiPost("/roles/{$role->id}/assign", [
            'user_id' => 9999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    // ─── Remove user ────────────────────────────────────────────

    public function test_remove_user_from_role(): void
    {
        $admin = $this->actingAsAdmin();
        $role = $this->createCustomRole();
        $user = User::factory()->create();

        $role->users()->attach($user->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
        ]);

        $response = $this->apiPost("/roles/{$role->id}/remove", [
            'user_id' => $user->id,
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Role retire.']);

        $this->assertDatabaseMissing('role_user', [
            'role_id' => $role->id,
            'user_id' => $user->id,
        ]);
    }

    // ─── Duplicate ──────────────────────────────────────────────

    public function test_duplicate_role(): void
    {
        $this->actingAsAdmin();
        $role = $this->createCustomRole(['nom' => 'Original']);

        $response = $this->apiPost("/roles/{$role->id}/duplicate");

        $response->assertStatus(201)
            ->assertJsonFragment(['is_system' => false]);

        $data = $response->json();
        $this->assertStringContainsString('(copie)', $data['nom']);
    }

    // ─── Permission enforcement ─────────────────────────────────

    public function test_user_without_settings_admin_cannot_access_roles(): void
    {
        // User with only collaborateurs permission
        $this->actingAsUser([], [
            'collaborateurs' => 'admin',
            'settings' => 'none',
        ]);

        $response = $this->apiGet('/roles');

        $response->assertStatus(403);
    }

    public function test_user_with_settings_admin_can_access_roles(): void
    {
        $this->actingAsUser([], ['settings' => 'admin']);

        $response = $this->apiGet('/roles');

        $response->assertOk();
    }

    // ─── Permissions schema ─────────────────────────────────────

    public function test_permissions_schema(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('/permissions/schema');

        $response->assertOk()
            ->assertJsonStructure(['modules', 'levels']);
    }
}
