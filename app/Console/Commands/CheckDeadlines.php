<?php

namespace App\Console\Commands;

use App\Events\AnniversaireEmbauche;
use App\Events\CollaborateurEnRetard;
use App\Events\DeadlineApproaching;
use App\Events\PeriodeEssaiTerminee;
use App\Models\Collaborateur;
use App\Models\CollaborateurAction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckDeadlines extends Command
{
    protected $signature = 'workflows:check-scheduled';

    protected $description = 'Fire scheduled workflow events: deadlines, période d\'essai, anniversaires, retards';

    public function handle(): int
    {
        $this->checkDeadlines();
        $this->checkPeriodeEssai();
        $this->checkAnniversaires();
        $this->checkRetards();

        return self::SUCCESS;
    }

    /**
     * Fire DeadlineApproaching events for actions due in 7 days.
     */
    private function checkDeadlines(): void
    {
        $targetDate = Carbon::now()->addDays(7)->toDateString();

        $assignments = CollaborateurAction::with(['collaborateur', 'action'])
            ->where('status', 'a_faire')
            ->get();

        $fired = 0;

        foreach ($assignments as $assignment) {
            $collaborateur = $assignment->collaborateur;
            $action = $assignment->action;

            if (!$collaborateur || !$action || !$collaborateur->date_debut || !$action->delai_relatif) {
                continue;
            }

            // Parse delai_relatif like "J+30" or "J+7"
            if (preg_match('/J\+(\d+)/', $action->delai_relatif, $matches)) {
                $days = (int) $matches[1];
                $deadline = $collaborateur->date_debut->copy()->addDays($days);

                if ($deadline->toDateString() === $targetDate) {
                    DeadlineApproaching::dispatch(
                        $collaborateur->id,
                        $action->titre,
                        $deadline->toDateString()
                    );
                    $fired++;
                }
            }
        }

        $this->info("Fired {$fired} DeadlineApproaching event(s).");
    }

    /**
     * Fire PeriodeEssaiTerminee for collaborateurs whose trial period ends today.
     * Trial period = date_debut + 3 months (or date_fin_essai if set).
     */
    private function checkPeriodeEssai(): void
    {
        $today = Carbon::today()->toDateString();
        $fired = 0;

        $collaborateurs = Collaborateur::whereNotNull('date_debut')
            ->where('status', '!=', 'termine')
            ->get();

        foreach ($collaborateurs as $collaborateur) {
            // Use explicit date_fin_essai if set, otherwise date_debut + 3 months
            if ($collaborateur->date_fin_essai) {
                $finEssai = Carbon::parse($collaborateur->date_fin_essai)->toDateString();
            } else {
                $finEssai = $collaborateur->date_debut->copy()->addMonths(3)->toDateString();
            }

            if ($finEssai === $today) {
                PeriodeEssaiTerminee::dispatch($collaborateur->id);
                $fired++;
            }
        }

        $this->info("Fired {$fired} PeriodeEssaiTerminee event(s).");
    }

    /**
     * Fire AnniversaireEmbauche for collaborateurs whose hire date anniversary is today.
     */
    private function checkAnniversaires(): void
    {
        $today = Carbon::today();
        $fired = 0;

        $collaborateurs = Collaborateur::whereNotNull('date_debut')
            ->where('status', '!=', 'termine')
            ->get();

        foreach ($collaborateurs as $collaborateur) {
            $dateDebut = $collaborateur->date_debut;

            // Same month and day, but not the hire year itself
            if ($dateDebut->month === $today->month
                && $dateDebut->day === $today->day
                && $dateDebut->year < $today->year
            ) {
                $years = $today->year - $dateDebut->year;
                AnniversaireEmbauche::dispatch($collaborateur->id, $years);
                $fired++;
            }
        }

        $this->info("Fired {$fired} AnniversaireEmbauche event(s).");
    }

    /**
     * Fire CollaborateurEnRetard for collaborateurs whose actual progression
     * is >20% behind their expected progression based on elapsed time.
     */
    private function checkRetards(): void
    {
        $today = Carbon::today();
        $fired = 0;

        $collaborateurs = Collaborateur::whereNotNull('date_debut')
            ->where('status', '!=', 'termine')
            ->get();

        foreach ($collaborateurs as $collaborateur) {
            $dateDebut = $collaborateur->date_debut;
            $progression = $collaborateur->progression ?? 0;

            // Skip if just started (less than 7 days)
            $daysElapsed = $dateDebut->diffInDays($today);
            if ($daysElapsed < 7) {
                continue;
            }

            // Estimate expected progression based on actions ratio
            $totalActions = $collaborateur->actions_total ?? 0;
            if ($totalActions === 0) {
                continue;
            }

            // Compute expected progression: assume a 90-day standard onboarding
            $onboardingDuration = 90;
            $expectedProgression = min(100, (int) round(($daysElapsed / $onboardingDuration) * 100));

            // Fire event if actual progression is >20% behind expected
            if ($expectedProgression - $progression > 20) {
                CollaborateurEnRetard::dispatch($collaborateur->id, $progression, $expectedProgression);
                $fired++;
            }
        }

        $this->info("Fired {$fired} CollaborateurEnRetard event(s).");
    }
}
