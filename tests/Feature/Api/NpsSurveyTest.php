<?php

namespace Tests\Feature\Api;

use App\Models\Collaborateur;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;

class NpsSurveyTest extends ApiTestCase
{
    private function createSurvey(array $attrs = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'titre' => 'Enquete satisfaction',
            'type' => 'nps',
            'declencheur' => 'manuel',
            'questions' => [
                ['text' => 'Recommanderiez-vous Illizeo ?', 'type' => 'nps'],
            ],
            'actif' => true,
        ], $attrs));
    }

    private function createCollaborateur(array $attrs = []): Collaborateur
    {
        return Collaborateur::create(array_merge([
            'prenom' => 'Test',
            'nom' => 'Collab',
            'email' => 'collab_' . uniqid() . '@example.com',
            'initials' => 'TC',
            'status' => 'actif',
        ], $attrs));
    }

    // ─── Index ──────────────────────────────────────────────────

    public function test_list_surveys_as_admin(): void
    {
        $this->actingAsAdmin();
        $this->createSurvey();

        $response = $this->apiGet('/nps-surveys');

        $response->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_surveys_with_permission(): void
    {
        $this->actingAsUser([], ['nps' => 'view']);
        $this->createSurvey();

        $response = $this->apiGet('/nps-surveys');

        $response->assertOk();
    }

    public function test_list_surveys_without_permission(): void
    {
        $this->actingAsUser([], ['nps' => 'none']);

        $response = $this->apiGet('/nps-surveys');

        $response->assertStatus(403);
    }

    public function test_list_surveys_unauthenticated(): void
    {
        $response = $this->apiGet('/nps-surveys');

        $response->assertStatus(401);
    }

    // ─── Store ──────────────────────────────────────────────────

    public function test_create_survey_success(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/nps-surveys', [
            'titre' => 'NPS Q1 2026',
            'type' => 'nps',
            'declencheur' => 'manuel',
            'questions' => [
                ['text' => 'Score NPS', 'type' => 'nps'],
                ['text' => 'Commentaire', 'type' => 'text'],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['titre' => 'NPS Q1 2026']);

        $this->assertDatabaseHas('nps_surveys', ['titre' => 'NPS Q1 2026']);
    }

    public function test_create_survey_validation_errors(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/nps-surveys', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['titre', 'questions']);
    }

    public function test_create_survey_invalid_question_type(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiPost('/nps-surveys', [
            'titre' => 'Test',
            'questions' => [
                ['text' => 'Q1', 'type' => 'invalid_type'],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_create_survey_requires_edit_permission(): void
    {
        $this->actingAsUser([], ['nps' => 'view']);

        $response = $this->apiPost('/nps-surveys', [
            'titre' => 'Blocked',
            'questions' => [['text' => 'Q', 'type' => 'nps']],
        ]);

        $response->assertStatus(403);
    }

    // ─── Show ───────────────────────────────────────────────────

    public function test_show_survey(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();

        $response = $this->apiGet("/nps-surveys/{$survey->id}");

        $response->assertOk()
            ->assertJsonFragment(['titre' => 'Enquete satisfaction']);
    }

    public function test_show_nonexistent_survey(): void
    {
        $this->actingAsAdmin();

        $response = $this->apiGet('/nps-surveys/9999');

        $response->assertStatus(404);
    }

    // ─── Update ─────────────────────────────────────────────────

    public function test_update_survey(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();

        $response = $this->apiPut("/nps-surveys/{$survey->id}", [
            'titre' => 'Enquete modifiee',
            'actif' => false,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['titre' => 'Enquete modifiee']);
    }

    // ─── Delete ─────────────────────────────────────────────────

    public function test_delete_survey(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();

        $response = $this->apiDelete("/nps-surveys/{$survey->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('nps_surveys', ['id' => $survey->id]);
    }

    // ─── Send to collaborateur ──────────────────────────────────

    public function test_send_survey_to_collaborateur(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();
        $collab = $this->createCollaborateur();

        $response = $this->apiPost("/nps-surveys/{$survey->id}/send", [
            'collaborateur_id' => $collab->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('nps_responses', [
            'survey_id' => $survey->id,
            'collaborateur_id' => $collab->id,
        ]);
    }

    public function test_send_survey_invalid_collaborateur(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();

        $response = $this->apiPost("/nps-surveys/{$survey->id}/send", [
            'collaborateur_id' => 9999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['collaborateur_id']);
    }

    // ─── Public response (token-based, no auth) ─────────────────

    public function test_respond_to_survey_by_token(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();
        $collab = $this->createCollaborateur();

        $npsResponse = NpsResponse::create([
            'survey_id' => $survey->id,
            'collaborateur_id' => $collab->id,
            'user_id' => auth()->id(),
        ]);

        $token = $npsResponse->token;

        // GET survey by token (public route, still tenant-scoped)
        $getResponse = $this->withoutTenancyMiddleware()
            ->getJson("/api/v1/nps/respond/{$token}");

        $getResponse->assertOk()
            ->assertJsonFragment(['survey_id' => $survey->id]);

        // POST response
        $postResponse = $this->withoutTenancyMiddleware()
            ->postJson("/api/v1/nps/respond/{$token}", [
                'score' => 9,
                'comment' => 'Excellent onboarding !',
            ]);

        $postResponse->assertOk();

        $npsResponse->refresh();
        $this->assertEquals(9, $npsResponse->score);
        $this->assertNotNull($npsResponse->completed_at);
    }

    public function test_cannot_respond_twice_to_same_survey(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();
        $collab = $this->createCollaborateur();

        $npsResponse = NpsResponse::create([
            'survey_id' => $survey->id,
            'collaborateur_id' => $collab->id,
            'user_id' => auth()->id(),
            'score' => 8,
            'completed_at' => now(),
        ]);

        $response = $this->withoutTenancyMiddleware()
            ->postJson("/api/v1/nps/respond/{$npsResponse->token}", [
                'score' => 10,
            ]);

        $response->assertStatus(410);
    }

    // ─── Stats ──────────────────────────────────────────────────

    public function test_nps_stats(): void
    {
        $this->actingAsAdmin();
        $survey = $this->createSurvey();
        $collab = $this->createCollaborateur();

        // Create some responses
        NpsResponse::create([
            'survey_id' => $survey->id,
            'collaborateur_id' => $collab->id,
            'user_id' => auth()->id(),
            'score' => 10,
            'completed_at' => now(),
        ]);

        $response = $this->apiGet('/nps-stats');

        $response->assertOk()
            ->assertJsonStructure([
                'nps_score',
                'avg_rating',
                'response_rate',
                'total_responses',
                'total_completed',
                'promoters',
                'passives',
                'detractors',
                'distribution',
                'evolution',
            ]);
    }
}
