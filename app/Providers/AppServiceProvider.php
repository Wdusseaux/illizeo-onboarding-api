<?php

namespace App\Providers;

use App\Events\ActionCompleted;
use App\Events\AllDocumentsValidated;
use App\Events\AnniversaireEmbauche;
use App\Events\CollaborateurEnRetard;
use App\Events\ContratReady;
use App\Events\ContratSigned;
use App\Events\CooptationValidated;
use App\Events\DeadlineApproaching;
use App\Events\DocumentRefused;
use App\Events\DocumentSubmitted;
use App\Events\DocumentValidated;
use App\Events\FormulaireSubmitted;
use App\Events\MessageReceived;
use App\Events\NewCollaborateur;
use App\Events\NpsSoumis;
use App\Events\ParcoursCompleted;
use App\Events\ParcoursCreated;
use App\Events\ParcoursOffboardingTermine;
use App\Events\PeriodeEssaiTerminee;
use App\Events\PostArrivalMilestone;
use App\Events\PreArrivalReminder;
use App\Events\SignatureReminder;
use App\Events\WeeklyDigest;
use App\Listeners\AssignParcoursActions;
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

        // Auto-assign Spatie role `collaborateur` when a Collaborateur fiche is created
        \App\Models\Collaborateur::observe(\App\Observers\CollaborateurObserver::class);

        // Workflow engine: listen to all onboarding events
        $workflowEvents = [
            DocumentSubmitted::class,
            DocumentValidated::class,
            DocumentRefused::class,
            AllDocumentsValidated::class,
            ParcoursCreated::class,
            ParcoursCompleted::class,
            ParcoursOffboardingTermine::class,
            ActionCompleted::class,
            FormulaireSubmitted::class,
            NewCollaborateur::class,
            DeadlineApproaching::class,
            PreArrivalReminder::class,
            PostArrivalMilestone::class,
            PeriodeEssaiTerminee::class,
            AnniversaireEmbauche::class,
            CollaborateurEnRetard::class,
            WeeklyDigest::class,
            SignatureReminder::class,
            ContratReady::class,
            ContratSigned::class,
            CooptationValidated::class,
            NpsSoumis::class,
            MessageReceived::class,
        ];

        foreach ($workflowEvents as $event) {
            Event::listen($event, WorkflowListener::class);
        }

        // Auto-create per-collaborateur action assignments when a parcours is assigned
        Event::listen(ParcoursCreated::class, AssignParcoursActions::class);

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
