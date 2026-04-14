<?php

namespace Tests\Feature\Api;

use App\Models\Parcours;
use App\Models\ParcoursCategorie;

class ParcoursTest extends ApiTestCase
{
    private function createCategorie(array $attrs = []): ParcoursCategorie
    {
        return ParcoursCategorie::create(array_merge([
            'slug' => 'onboarding',
            'nom' => 'Onboarding',
        ], $attrs));
    }

    private function createParcours(array $attrs = []): Parcours
    {
        if (empty($attrs['categorie_id'])) {
            $cat = $this->createCategorie();
            $attrs['categorie_id'] = $cat->id;
        }

        return Parcours::create(array_merge([
            'nom' => 'Parcours de test',
        ], $attrs));
    }

    // ─── Index ──────────────────────────────────────────────────

    public function test_list_parcours_as_admin(): void
    {
        $this->actingAsAdmin();
        $this->createParcours();

        $response = $this->apiGet('/parcours');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_parcours_with_view_permission(): void
    {
        $this->actingAsUser([], ['parcours' => 'view']);
        $this->createParcours();

        $response = $this->apiGet('/parcours');

        $response->assertOk();
    }

    public function test_list_parcours_without_permission(): void
    {
        $this->actingAsUser([], ['parcours' => 'none']);

        $response = $this->apiGet('/parcours');

        $response->assertStatus(403);
    }

    public function test_list_parcours_unauthenticated(): void
    {
        $response = $this->apiGet('/parcours');

        $response->assertStatus(401);
    }

    // ─── Store ──────────────────────────────────────────────────

    public function test_create_parcours_success(): void
    {
        $this->actingAsAdmin();
        $cat = $this->createCategorie();

        $response = $this->apiPost('/parcours', [
            'nom' => 'Nouveau parcours',
            'categorie_id' => $cat->id,
            'status' => 'brouillon',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['nom' => 'Nouveau parcours']);

        $this->assertDatabaseHas('parcours', ['nom' => 'Nouveau parcours']);
    }

    public function test_create_parcours_validation_errors(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/parcours', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nom', 'categorie_id']);
    }

    public function test_create_parcours_invalid_categorie(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/parcours', [
            'nom' => 'Test',
            'categorie_id' => 9999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['categorie_id']);
    }

    public function test_create_parcours_invalid_status(): void
    {
        $this->actingAsAdmin();
        $cat = $this->createCategorie();

        $response = $this->apiPost('/parcours', [
            'nom' => 'Test',
            'categorie_id' => $cat->id,
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_create_parcours_requires_edit_permission(): void
    {
        $this->actingAsUser([], ['parcours' => 'view']);
        $cat = $this->createCategorie();

        $response = $this->apiPost('/parcours', [
            'nom' => 'Blocked',
            'categorie_id' => $cat->id,
        ]);

        $response->assertStatus(403);
    }

    // ─── Show ───────────────────────────────────────────────────

    public function test_show_parcours(): void
    {
        $this->actingAsAdmin();
        $parcours = $this->createParcours();

        $response = $this->apiGet("/parcours/{$parcours->id}");

        $response->assertOk()
            ->assertJsonFragment(['nom' => 'Parcours de test']);
    }

    public function test_show_nonexistent_parcours(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('/parcours/9999');

        $response->assertStatus(404);
    }

    // ─── Update ─────────────────────────────────────────────────

    public function test_update_parcours(): void
    {
        $this->actingAsAdmin();
        $parcours = $this->createParcours();

        $response = $this->apiPut("/parcours/{$parcours->id}", [
            'nom' => 'Parcours mis a jour',
            'status' => 'actif',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['nom' => 'Parcours mis a jour']);
    }

    // ─── Delete ─────────────────────────────────────────────────

    public function test_delete_parcours(): void
    {
        $this->actingAsAdmin();
        $parcours = $this->createParcours();

        $response = $this->apiDelete("/parcours/{$parcours->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('parcours', ['id' => $parcours->id]);
    }

    // ─── Duplicate ──────────────────────────────────────────────

    public function test_duplicate_parcours(): void
    {
        $this->actingAsAdmin();
        $parcours = $this->createParcours(['status' => 'actif']);

        $response = $this->apiPost("/parcours/{$parcours->id}/duplicate");

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'brouillon']);

        $this->assertDatabaseCount('parcours', 2);
    }
}
