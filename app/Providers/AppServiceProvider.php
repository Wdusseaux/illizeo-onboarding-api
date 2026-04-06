<?php

namespace App\Providers;

use App\Events\ActionCompleted;
use App\Events\AllDocumentsValidated;
use App\Events\AnniversaireEmbauche;
use App\Events\CollaborateurEnRetard;
use App\Events\ContratSigned;
use App\Events\CooptationValidated;
use App\Events\DeadlineApproaching;
use App\Events\DocumentRefused;
use App\Events\DocumentSubmitted;
use App\Events\FormulaireSubmitted;
use App\Events\NewCollaborateur;
use App\Events\NpsSoumis;
use App\Events\ParcoursCompleted;
use App\Events\ParcoursCreated;
use App\Events\ParcoursOffboardingTermine;
use App\Events\PeriodeEssaiTerminee;
use App\Listeners\WorkflowListener;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super admin bypasses all permission checks
        Gate::before(function ($user) {
            return $user->hasRole('super_admin') ? true : null;
        });

        // Workflow engine: listen to all onboarding events
        $workflowEvents = [
            DocumentSubmitted::class,
            ParcoursCreated::class,
            ActionCompleted::class,
            ParcoursCompleted::class,
            FormulaireSubmitted::class,
            AllDocumentsValidated::class,
            NewCollaborateur::class,
            DeadlineApproaching::class,
            PeriodeEssaiTerminee::class,
            AnniversaireEmbauche::class,
            CollaborateurEnRetard::class,
            DocumentRefused::class,
            CooptationValidated::class,
            ContratSigned::class,
            ParcoursOffboardingTermine::class,
            NpsSoumis::class,
        ];

        foreach ($workflowEvents as $event) {
            Event::listen($event, WorkflowListener::class);
        }

        // Single recipient: redirect all emails in dev to one address
        $singleRecipient = env('MAIL_SINGLE_RECIPIENT');
        if ($singleRecipient) {
            Event::listen(MessageSending::class, function (MessageSending $event) use ($singleRecipient) {
                $original = collect($event->message->getTo())->map(fn ($a) => $a->getAddress())->implode(', ');
                $event->message->to($singleRecipient);
                $event->message->getHeaders()->addTextHeader('X-Original-To', $original);
            });
        }
    }
}
