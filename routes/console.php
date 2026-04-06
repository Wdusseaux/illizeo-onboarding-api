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

// Activate pending downgrades — runs daily at 00:05
Schedule::call(function () {
    $pending = \App\Models\Subscription::where('status', 'pending')
        ->where('current_period_start', '<=', now())
        ->get();

    foreach ($pending as $sub) {
        // Cancel the old active subscription for this tenant/category
        $isCoopt = $sub->plan && $sub->plan->slug === 'cooptation';
        \App\Models\Subscription::where('tenant_id', $sub->tenant_id)
            ->where('id', '!=', $sub->id)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', fn ($q) => $isCoopt
                ? $q->where('slug', 'cooptation')
                : $q->where('slug', '!=', 'cooptation'))
            ->update(['status' => 'canceled', 'canceled_at' => now()]);

        // Activate the pending subscription
        $sub->update(['status' => 'active']);

        // Sync modules for this tenant
        $tenant = \App\Models\Tenant::find($sub->tenant_id);
        if ($tenant) {
            tenancy()->initialize($tenant);
            \App\Models\TenantActiveModule::query()->delete();
            $activeSubs = \App\Models\Subscription::where('tenant_id', $sub->tenant_id)
                ->whereIn('status', ['active', 'trialing'])
                ->with('plan.modules')
                ->get();
            foreach ($activeSubs as $activeSub) {
                foreach ($activeSub->plan->modules as $mod) {
                    if ($mod->actif) {
                        \App\Models\TenantActiveModule::create([
                            'module' => $mod->module,
                            'source_plan_id' => $activeSub->plan_id,
                            'actif' => true,
                        ]);
                    }
                }
            }
            tenancy()->end();
        }

        \Log::info("Downgrade activated for tenant {$sub->tenant_id} — plan {$sub->plan_id}");
    }
})->dailyAt('00:05');
