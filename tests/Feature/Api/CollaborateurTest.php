<?php

namespace Tests\Feature\Api;

use App\Models\Collaborateur;
use App\Models\Parcours;
use App\Models\ParcoursCategorie;

class CollaborateurTest extends ApiTestCase
{
    // ─── Index ──────────────────────────────────────────────────

    public function test_list_collaborateurs_as_admin(): void
    {
        $this->actingAsAdmin();

        Collaborateur::create([
            'prenom' => 'Marie',
            'nom' => 'Curie',
            'email' => 'marie@example.com',
            'initials' => 'MC',
        ]);

        $response = $this->apiGet('/collaborateurs');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_collaborateurs_with_permission(): void
    {
        $this->actingAsUser([], ['collaborateurs' => 'view']);

        $response = $this->apiGet('/collaborateurs');

        $response->assertOk();
    }

    public function test_list_collaborateurs_without_permission(): void
    {
        $this->actingAsUser([], ['collaborateurs' => 'none']);

        $response = $this->apiGet('/collaborateurs');

        $response->assertStatus(403);
    }

    public function test_list_collaborateurs_unauthenticated(): void
    {
        $response = $this->apiGet('/collaborateurs');

        $response->assertStatus(401);
    }

    public function test_list_collaborateurs_filter_by_status(): void
    {
        $this->actingAsAdmin();

        Collaborateur::create([
            'prenom' => 'Active',
            'nom' => 'User',
            'email' => 'active@example.com',
            'initials' => 'AU',
            'status' => 'en_cours',
        ]);
        Collaborateur::create([
            'prenom' => 'Done',
            'nom' => 'User',
            'email' => 'done@example.com',
            'initials' => 'DU',
            'status' => 'termine',
        ]);

        $response = $this->apiGet('/collaborateurs?status=en_cours');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    // ─── Store ──────────────────────────────────────────────────

    public function test_create_collaborateur_success(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/collaborateurs', [
            'prenom' => 'Albert',
            'nom' => 'Einstein',
            'email' => 'albert@example.com',
            'poste' => 'Physicien',
            'site' => 'Berne',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'prenom' => 'Albert',
                'nom' => 'Einstein',
                'initials' => 'AE',
            ]);

        $this->assertDatabaseHas('collaborateurs', ['email' => 'albert@example.com']);
    }

    public function test_create_collaborateur_with_parcours(): void
    {
        $this->actingAsAdmin();

        $categorie = ParcoursCategorie::create([
            'slug' => 'onboarding',
            'nom' => 'Onboarding',
        ]);
        $parcours = Parcours::create([
            'nom' => 'Parcours test',
            'categorie_id' => $categorie->id,
        ]);

        $response = $this->apiPost('/collaborateurs', [
            'prenom' => 'Isaac',
            'nom' => 'Newton',
            'email' => 'isaac@example.com',
            'parcours_id' => $parcours->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['parcours_id' => $parcours->id]);
    }

    public function test_create_collaborateur_validation_errors(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/collaborateurs', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['prenom', 'nom', 'email']);
    }

    public function test_create_collaborateur_duplicate_email(): void
    {
        $this->actingAsAdmin();

        Collaborateur::create([
            'prenom' => 'First',
            'nom' => 'User',
            'email' => 'dupe@example.com',
            'initials' => 'FU',
        ]);

        $response = $this->apiPost('/collaborateurs', [
            'prenom' => 'Second',
            'nom' => 'User',
            'email' => 'dupe@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_create_collaborateur_requires_edit_permission(): void
    {
        $this->actingAsUser([], ['collaborateurs' => 'view']);

        $response = $this->apiPost('/collaborateurs', [
            'prenom' => 'Test',
            'nom' => 'Denied',
            'email' => 'denied@example.com',
        ]);

        $response->assertStatus(403);
    }

    // ─── Show ───────────────────────────────────────────────────

    public function test_show_collaborateur(): void
    {
        $this->actingAsAdmin();

        $collab = Collaborateur::create([
            'prenom' => 'Nikola',
            'nom' => 'Tesla',
            'email' => 'nikola@example.com',
            'initials' => 'NT',
        ]);

        $response = $this->apiGet("/collaborateurs/{$collab->id}");

        $response->assertOk()
            ->assertJsonFragment(['prenom' => 'Nikola']);
    }

    public function test_show_nonexistent_collaborateur(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('/collaborateurs/9999');

        $response->assertStatus(404);
    }

    // ─── Update ─────────────────────────────────────────────────

    public function test_update_collaborateur(): void
    {
        $this->actingAsAdmin();

        $collab = Collaborateur::create([
            'prenom' => 'Ada',
            'nom' => 'Lovelace',
            'email' => 'ada@example.com',
            'initials' => 'AL',
        ]);

        $response = $this->apiPut("/collaborateurs/{$collab->id}", [
            'poste' => 'Mathematician',
            'departement' => 'R&D',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['poste' => 'Mathematician']);
    }

    // ─── Delete ─────────────────────────────────────────────────

    public function test_delete_collaborateur(): void
    {
        $this->actingAsAdmin();

        $collab = Collaborateur::create([
            'prenom' => 'Temp',
            'nom' => 'User',
            'email' => 'temp@example.com',
            'initials' => 'TU',
        ]);

        $response = $this->apiDelete("/collaborateurs/{$collab->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('collaborateurs', ['id' => $collab->id]);
    }
}
