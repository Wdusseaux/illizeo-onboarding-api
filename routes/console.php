<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Workflow scheduled checks — runs daily at 08:00
Schedule::command('workflows:check-scheduled')->dailyAt('08:00');

// Cooptation deadline reminder — notify admin_rh 7 days before validation date
Schedule::call(function () {
    $tenants = \App\Models\Tenant::all();
    foreach ($tenants as $tenant) {
        tenancy()->initialize($tenant);

        $cooptations = \App\Models\Cooptation::where('statut', 'embauche')
            ->whereNotNull('date_validation')
            ->whereDate('date_validation', now()->addDays(7)->toDateString())
            ->get();

        if ($cooptations->isNotEmpty()) {
            $adminRhIds = \App\Models\User::role('admin_rh')->pluck('id');
            foreach ($cooptations as $cooptation) {
                foreach ($adminRhIds as $userId) {
                    \App\Services\NotificationService::send($userId, 'cooptation',
                        'Validation cooptation imminente',
                        "La cooptation de {$cooptation->candidate_name} (parrain : {$cooptation->referrer_name}) arrive à échéance dans 7 jours.",
                        'clock', '#F9A825');
                }
            }
        }

        tenancy()->end();
    }
})->dailyAt('08:30');

// Billing: expire trials, activate pending downgrades, renew subscriptions, charge payments, retry failures
// Runs daily at 00:15 — replaces the old pending downgrade handler
Schedule::command('billing:process')->dailyAt('00:15');
