<?php

namespace Tests\Feature\Api;

use App\Models\Collaborateur;
use App\Models\Equipment;
use App\Models\EquipmentType;

class EquipmentTest extends ApiTestCase
{
    private function createEquipmentType(array $attrs = []): EquipmentType
    {
        return EquipmentType::create(array_merge([
            'nom' => 'Laptop',
            'icon' => 'laptop',
            'actif' => true,
        ], $attrs));
    }

    private function createEquipment(array $attrs = []): Equipment
    {
        if (empty($attrs['equipment_type_id'])) {
            $attrs['equipment_type_id'] = $this->createEquipmentType()->id;
        }

        return Equipment::create(array_merge([
            'nom' => 'MacBook Pro 16"',
            'etat' => 'disponible',
        ], $attrs));
    }

    private function createCollaborateur(): Collaborateur
    {
        return Collaborateur::create([
            'prenom' => 'Test',
            'nom' => 'Collab',
            'email' => 'collab_' . uniqid() . '@example.com',
            'initials' => 'TC',
        ]);
    }

    // ─── Index ──────────────────────────────────────────────────

    public function test_list_equipments_as_admin(): void
    {
        $this->actingAsAdmin();
        $this->createEquipment();

        $response = $this->apiGet('/equipments');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_equipments_with_permission(): void
    {
        $this->actingAsUser([], ['equipements' => 'view']);
        $this->createEquipment();

        $response = $this->apiGet('/equipments');

        $response->assertOk();
    }

    public function test_list_equipments_without_permission(): void
    {
        $this->actingAsUser([], ['equipements' => 'none']);

        $response = $this->apiGet('/equipments');

        $response->assertStatus(403);
    }

    public function test_list_equipments_unauthenticated(): void
    {
        $response = $this->apiGet('/equipments');

        $response->assertStatus(401);
    }

    public function test_list_equipments_filter_by_etat(): void
    {
        $this->actingAsAdmin();
        $type = $this->createEquipmentType();

        Equipment::create(['equipment_type_id' => $type->id, 'nom' => 'Available', 'etat' => 'disponible']);
        Equipment::create(['equipment_type_id' => $type->id, 'nom' => 'In repair', 'etat' => 'en_reparation']);

        $response = $this->apiGet('/equipments?etat=disponible');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    // ─── Store ──────────────────────────────────────────────────

    public function test_create_equipment_success(): void
    {
        $this->actingAsAdmin();
        $type = $this->createEquipmentType();

        $response = $this->apiPost('/equipments', [
            'equipment_type_id' => $type->id,
            'nom' => 'Dell XPS 15',
            'numero_serie' => 'SN-12345',
            'marque' => 'Dell',
            'modele' => 'XPS 15',
            'etat' => 'disponible',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['nom' => 'Dell XPS 15']);

        $this->assertDatabaseHas('equipments', ['numero_serie' => 'SN-12345']);
    }

    public function test_create_equipment_validation_errors(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/equipments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipment_type_id', 'nom']);
    }

    public function test_create_equipment_invalid_type(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/equipments', [
            'equipment_type_id' => 9999,
            'nom' => 'Test',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipment_type_id']);
    }

    public function test_create_equipment_requires_edit_permission(): void
    {
        $this->actingAsUser([], ['equipements' => 'view']);
        $type = $this->createEquipmentType();

        $response = $this->apiPost('/equipments', [
            'equipment_type_id' => $type->id,
            'nom' => 'Blocked',
        ]);

        $response->assertStatus(403);
    }

    // ─── Show ───────────────────────────────────────────────────

    public function test_show_equipment(): void
    {
        $this->actingAsAdmin();
        $equip = $this->createEquipment();

        $response = $this->apiGet("/equipments/{$equip->id}");

        $response->assertOk()
            ->assertJsonFragment(['nom' => 'MacBook Pro 16"']);
    }

    // ─── Update ─────────────────────────────────────────────────

    public function test_update_equipment(): void
    {
        $this->actingAsAdmin();
        $equip = $this->createEquipment();

        $response = $this->apiPut("/equipments/{$equip->id}", [
            'nom' => 'Updated Laptop',
            'etat' => 'en_reparation',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['nom' => 'Updated Laptop']);
    }

    // ─── Delete ─────────────────────────────────────────────────

    public function test_delete_equipment(): void
    {
        $this->actingAsAdmin();
        $equip = $this->createEquipment();

        $response = $this->apiDelete("/equipments/{$equip->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('equipments', ['id' => $equip->id]);
    }

    // ─── Assign ─────────────────────────────────────────────────

    public function test_assign_equipment_to_collaborateur(): void
    {
        $this->actingAsAdmin();
        $equip = $this->createEquipment();
        $collab = $this->createCollaborateur();

        $response = $this->apiPost("/equipments/{$equip->id}/assign", [
            'collaborateur_id' => $collab->id,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['etat' => 'attribue']);

        $this->assertDatabaseHas('equipments', [
            'id' => $equip->id,
            'collaborateur_id' => $collab->id,
        ]);
    }

    public function test_assign_equipment_invalid_collaborateur(): void
    {
        $this->actingAsAdmin();
        $equip = $this->createEquipment();

        $response = $this->apiPost("/equipments/{$equip->id}/assign", [
            'collaborateur_id' => 9999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['collaborateur_id']);
    }

    // ─── Unassign ───────────────────────────────────────────────

    public function test_unassign_equipment(): void
    {
        $this->actingAsAdmin();
        $collab = $this->createCollaborateur();
        $type = $this->createEquipmentType();

        $equip = Equipment::create([
            'equipment_type_id' => $type->id,
            'nom' => 'Assigned Laptop',
            'etat' => 'attribue',
            'collaborateur_id' => $collab->id,
        ]);

        $response = $this->apiPost("/equipments/{$equip->id}/unassign");

        $response->assertOk()
            ->assertJsonFragment(['etat' => 'disponible']);

        $equip->refresh();
        $this->assertNull($equip->collaborateur_id);
    }

    // ─── Stats ──────────────────────────────────────────────────

    public function test_equipment_stats(): void
    {
        $this->actingAsAdmin();
        $type = $this->createEquipmentType();

        Equipment::create(['equipment_type_id' => $type->id, 'nom' => 'E1', 'etat' => 'disponible', 'valeur' => 1500]);
        Equipment::create(['equipment_type_id' => $type->id, 'nom' => 'E2', 'etat' => 'attribue', 'valeur' => 2000]);
        Equipment::create(['equipment_type_id' => $type->id, 'nom' => 'E3', 'etat' => 'en_reparation']);

        $response = $this->apiGet('/equipments/stats');

        $response->assertOk()
            ->assertJsonFragment([
                'total' => 3,
                'disponible' => 1,
                'attribue' => 1,
                'enReparation' => 1,
            ]);

        $data = $response->json();
        $this->assertEquals(3500, (float) $data['valeurTotale']);
    }

    // ─── Equipment Types ────────────────────────────────────────

    public function test_list_equipment_types(): void
    {
        $this->actingAsAdmin();
        $this->createEquipmentType();

        $response = $this->apiGet('/equipment-types');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    public function test_create_equipment_type(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/equipment-types', [
            'nom' => 'Ecran',
            'icon' => 'monitor',
            'description' => 'Ecran externe',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['nom' => 'Ecran']);
    }
}
