<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CalendarController extends Controller
{
    /**
     * Get calendar events aggregated from multiple sources:
     * - Collaborateur arrivals (date_debut)
     * - Probation end dates (date_fin_essai)
     * - Assigned actions with computed dates (date_debut + delai_relatif)
     * - Actions of type entretien, rdv, visite
     */
    public function index(Request $request): JsonResponse
    {
        $month = (int) $request->get('month', now()->month);
        $year = (int) $request->get('year', now()->year);

        $startOfMonth = Carbon::create($year, $month, 1)->startOfMonth()->subDays(7);
        $endOfMonth = Carbon::create($year, $month, 1)->endOfMonth()->addDays(7);

        $events = [];

        // ── 1. Collaborateur arrivals ──
        try {
            $collabs = \App\Models\Collaborateur::whereNotNull('date_debut')->get();

            foreach ($collabs as $c) {
                $dateDebut = Carbon::parse($c->date_debut);

                // Arrival date
                if ($dateDebut->between($startOfMonth, $endOfMonth)) {
                    $events[] = [
                        'date' => $dateDebut->toDateString(),
                        'type' => 'arrival',
                        'title' => "Arrivée de {$c->prenom} {$c->nom}",
                        'subtitle' => $c->poste ?? '',
                        'color' => '#4CAF50',
                        'collaborateur_id' => $c->id,
                    ];
                }

                // Probation end
                if ($c->date_fin_essai) {
                    $finEssai = Carbon::parse($c->date_fin_essai);
                    if ($finEssai->between($startOfMonth, $endOfMonth)) {
                        $events[] = [
                            'date' => $finEssai->toDateString(),
                            'type' => 'probation_end',
                            'title' => "Fin période d'essai — {$c->prenom} {$c->nom}",
                            'subtitle' => $c->poste ?? '',
                            'color' => '#F9A825',
                            'collaborateur_id' => $c->id,
                        ];
                    }
                }

                // ── 2. Assigned actions with computed dates ──
                try {
                    $assignedActions = \App\Models\CollaborateurAction::where('collaborateur_id', $c->id)
                        ->whereIn('status', ['a_faire', 'en_cours'])
                        ->with(['action.phase'])
                        ->get();

                    foreach ($assignedActions as $ca) {
                        $action = $ca->action;
                        if (!$action || !$action->delai_relatif) continue;

                        // Parse delai_relatif (J+7, J-30, etc.)
                        $match = preg_match('/J([+-]\d+)/', $action->delai_relatif, $m);
                        if (!$match) continue;

                        $days = (int) $m[1];
                        $actionDate = $dateDebut->copy()->addDays($days);

                        if (!$actionDate->between($startOfMonth, $endOfMonth)) continue;

                        // Determine color by action type
                        $typeSlug = $action->actionType->slug ?? 'tache';
                        $typeColors = [
                            'entretien' => '#E65100',
                            'rdv' => '#D81B60',
                            'visite' => '#2E7D32',
                            'formation' => '#7B5EA7',
                            'signature' => '#F9A825',
                            'document' => '#1A73E8',
                            'questionnaire' => '#7B5EA7',
                        ];
                        $color = $typeColors[$typeSlug] ?? '#1A73E8';

                        $events[] = [
                            'date' => $actionDate->toDateString(),
                            'type' => $typeSlug,
                            'title' => $action->titre,
                            'subtitle' => "{$c->prenom} {$c->nom}" . ($action->obligatoire ? ' · Obligatoire' : ''),
                            'color' => $color,
                            'collaborateur_id' => $c->id,
                            'action_id' => $action->id,
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip if assignedActions fails
                }
            }
        } catch (\Exception $e) {
            // Skip if collaborateurs fails
        }

        // Sort by date
        usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

        return response()->json($events);
    }
}
