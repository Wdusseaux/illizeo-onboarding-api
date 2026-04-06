<?php

namespace Database\Seeders;

use App\Models\Collaborateur;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use Illuminate\Database\Seeder;

class NpsSurveySeeder extends Seeder
{
    public function run(): void
    {
        $surveyNps = NpsSurvey::firstOrCreate(
            ['titre' => 'NPS Onboarding'],
            [
                'description' => "Enquête NPS envoyée à la fin du parcours d'onboarding",
                'type' => 'nps',
                'declencheur' => 'fin_parcours',
                'questions' => [
                    ['text' => "Sur une échelle de 0 à 10, recommanderiez-vous notre processus d'onboarding ?", 'type' => 'nps'],
                    ['text' => "Qu'est-ce qui pourrait être amélioré ?", 'type' => 'text'],
                ],
                'actif' => true,
            ]
        );

        $surveySat = NpsSurvey::firstOrCreate(
            ['titre' => 'Satisfaction 3 mois'],
            [
                'description' => "Enquête de satisfaction envoyée 3 mois après l'arrivée",
                'type' => 'satisfaction',
                'declencheur' => 'date_specifique',
                'questions' => [
                    ['text' => "Comment évaluez-vous votre intégration globale ?", 'type' => 'rating'],
                    ['text' => "Vous sentez-vous bien accompagné(e) par votre manager ?", 'type' => 'rating'],
                    ['text' => "Avez-vous des suggestions pour améliorer l'accueil des nouveaux arrivants ?", 'type' => 'text'],
                ],
                'actif' => true,
            ]
        );

        $collaborateurIds = Collaborateur::pluck('id')->take(5)->toArray();
        if (empty($collaborateurIds)) {
            return;
        }

        $npsResponses = [
            ['score' => 9, 'comment' => 'Excellent onboarding, très bien structuré !', 'months_ago' => 5],
            ['score' => 10, 'comment' => 'Parfait, rien à redire.', 'months_ago' => 4],
            ['score' => 7, 'comment' => "Bien dans l'ensemble, mais un peu long.", 'months_ago' => 4],
            ['score' => 6, 'comment' => 'Manque de suivi après la première semaine.', 'months_ago' => 3],
            ['score' => 8, 'comment' => null, 'months_ago' => 3],
            ['score' => 9, 'comment' => 'Super équipe RH, très disponible.', 'months_ago' => 2],
            ['score' => 4, 'comment' => 'Trop de paperasse, processus à simplifier.', 'months_ago' => 2],
            ['score' => 10, 'comment' => "Le meilleur onboarding que j'ai connu.", 'months_ago' => 1],
        ];

        foreach ($npsResponses as $i => $data) {
            $collabId = $collaborateurIds[$i % count($collaborateurIds)];
            NpsResponse::firstOrCreate(
                ['survey_id' => $surveyNps->id, 'collaborateur_id' => $collabId, 'score' => $data['score']],
                [
                    'answers' => [
                        ['question' => $surveyNps->questions[0]['text'], 'value' => $data['score']],
                        ['question' => $surveyNps->questions[1]['text'], 'value' => $data['comment'] ?? ''],
                    ],
                    'comment' => $data['comment'],
                    'completed_at' => now()->subMonths($data['months_ago'])->subDays(rand(0, 15)),
                ]
            );
        }

        $satResponses = [
            ['rating' => 4.5, 'comment' => 'Très bonne intégration.', 'months_ago' => 3],
            ['rating' => 3.0, 'comment' => 'Pourrait être mieux.', 'months_ago' => 2],
            ['rating' => 5.0, 'comment' => 'Rien à dire, tout est parfait.', 'months_ago' => 1],
        ];

        foreach ($satResponses as $i => $data) {
            $collabId = $collaborateurIds[$i % count($collaborateurIds)];
            NpsResponse::firstOrCreate(
                ['survey_id' => $surveySat->id, 'collaborateur_id' => $collabId, 'rating' => $data['rating']],
                [
                    'answers' => [
                        ['question' => $surveySat->questions[0]['text'], 'value' => $data['rating']],
                        ['question' => $surveySat->questions[1]['text'], 'value' => $data['rating']],
                        ['question' => $surveySat->questions[2]['text'], 'value' => $data['comment']],
                    ],
                    'comment' => $data['comment'],
                    'completed_at' => now()->subMonths($data['months_ago'])->subDays(rand(0, 10)),
                ]
            );
        }
    }
}
