<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthTest extends ApiTestCase
{
    // ─── Register ───────────────────────────────────────────────

    public function test_register_success(): void
    {
        $response = $this->apiPost('/register', [
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email'],
                'token',
            ]);

        $this->assertDatabaseHas('users', ['email' => 'jean@example.com']);
    }

    public function test_register_validation_errors(): void
    {
        // Missing required fields
        $response = $this->apiPost('/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->apiPost('/register', [
            'name' => 'Test',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_password_too_short(): void
    {
        $response = $this->apiPost('/register', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_password_confirmation_mismatch(): void
    {
        $response = $this->apiPost('/register', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── Login ──────────────────────────────────────────────────

    public function test_login_success(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->apiPost('/login', [
            'email' => 'user@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email'],
                'token',
            ]);
    }

    public function test_login_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->apiPost('/login', [
            'email' => 'user@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_nonexistent_user(): void
    {
        $response = $this->apiPost('/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    // ─── Logout ─────────────────────────────────────────────────

    public function test_logout_authenticated(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Déconnecté']);
    }

    public function test_logout_unauthenticated(): void
    {
        $response = $this->apiPost('/logout');

        $response->assertStatus(401);
    }

    // ─── Current user (/user acts as /me) ───────────────────────

    public function test_get_user_authenticated(): void
    {
        $user = $this->actingAsAdmin();

        $response = $this->apiGet('/user');

        $response->assertOk()
            ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_get_user_unauthenticated(): void
    {
        $response = $this->apiGet('/user');

        $response->assertStatus(401);
    }

    // ─── Permissions ────────────────────────────────────────────

    public function test_get_permissions_returns_structure(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('/me/permissions');

        $response->assertOk()
            ->assertJsonStructure([
                'permissions',
                'roles',
                'is_super_admin',
            ]);
    }

    public function test_get_permissions_unauthenticated(): void
    {
        $response = $this->apiGet('/me/permissions');

        $response->assertStatus(401);
    }

    public function test_get_permissions_super_admin_flag(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('/me/permissions');

        $response->assertOk()
            ->assertJson(['is_super_admin' => true]);
    }

    public function test_get_permissions_regular_user_not_super_admin(): void
    {
        $this->actingAsUser([], ['collaborateurs' => 'view']);

        $response = $this->apiGet('/me/permissions');

        $response->assertOk()
            ->assertJson(['is_super_admin' => false]);
    }
}
