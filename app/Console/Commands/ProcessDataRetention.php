<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ProcessDataRetention extends Command
{
    protected $signature = 'rgpd:process-retention {--dry-run : Show what would be done}';
    protected $description = 'RGPD data retention: warn and auto-delete tenant data 30 days after subscription/trial end';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $now = now();

        $this->info("Processing data retention at {$now->toDateTimeString()}" . ($dryRun ? ' [DRY RUN]' : ''));

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $this->processTenant($tenant, $now, $dryRun);
        }

        $this->info('Data retention processing complete.');
        return 0;
    }

    private function processTenant(Tenant $tenant, Carbon $now, bool $dryRun): void
    {
        // Check if tenant has any active/trialing subscription
        $hasActiveSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing', 'pending'])
            ->exists();

        if ($hasActiveSub) return; // Active subscription, skip

        // Find the most recent subscription end date or trial end
        $lastSub = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['canceled'])
            ->orderByDesc('current_period_end')
            ->first();

        $lastTrialSub = Subscription::where('tenant_id', $tenant->id)
            ->whereNotNull('trial_ends_at')
            ->orderByDesc('trial_ends_at')
            ->first();

        // Determine the "end date" — when the subscription/trial effectively ended
        $endDate = null;

        if ($lastSub && $lastSub->current_period_end) {
            $endDate = Carbon::parse($lastSub->current_period_end);
        }

        if ($lastTrialSub && $lastTrialSub->trial_ends_at) {
            $trialEnd = Carbon::parse($lastTrialSub->trial_ends_at);
            if (!$endDate || $trialEnd->isAfter($endDate)) {
                $endDate = $trialEnd;
            }
        }

        // If no subscription/trial ever existed, check tenant creation date
        if (!$endDate) {
            // New tenant with no subscription — use creation date + 14 days (default trial)
            $endDate = Carbon::parse($tenant->created_at)->addDays(14);
        }

        // If end date is in the future, skip
        if ($endDate->isFuture()) return;

        $daysSinceEnd = $endDate->diffInDays($now);
        $deletionDate = $endDate->copy()->addDays(30);
        $daysUntilDeletion = max(0, $now->diffInDays($deletionDate, false));

        // Get admin email for notifications
        $adminEmail = $this->getTenantAdminEmail($tenant->id);
        $tenantName = $tenant->nom ?? $tenant->id;

        // J-7: Send warning 7 days before deletion (day 23 after end)
        if ($daysSinceEnd >= 23 && $daysSinceEnd < 24) {
            $this->line("  [{$tenant->id}] Sending J-7 warning (deletion in {$daysUntilDeletion} days)");
            if (!$dryRun && $adminEmail) {
                $this->sendRetentionWarning($adminEmail, $tenantName, $daysUntilDeletion, $deletionDate);
            }
        }

        // J-1: Send final warning 24h before deletion (day 29 after end)
        if ($daysSinceEnd >= 29 && $daysSinceEnd < 30) {
            $this->line("  [{$tenant->id}] Sending J-1 FINAL warning (deletion tomorrow)");
            if (!$dryRun && $adminEmail) {
                $this->sendRetentionWarning($adminEmail, $tenantName, 1, $deletionDate, true);
            }
        }

        // D-Day: Delete data after 30 days
        if ($daysSinceEnd >= 30) {
            $this->line("  [{$tenant->id}] DELETING tenant data ({$daysSinceEnd} days since end)");
            if (!$dryRun) {
                $this->deleteTenantData($tenant);
            }
        }
    }

    /**
     * Send data retention warning email.
     */
    private function sendRetentionWarning(string $email, string $tenantName, int $daysLeft, Carbon $deletionDate, bool $isFinal = false): void
    {
        $formattedDate = $deletionDate->format('d/m/Y');
        $subject = $isFinal
            ? "URGENT : Suppression de vos données Illizeo demain — {$tenantName}"
            : "Avertissement : Suppression de vos données Illizeo dans {$daysLeft} jours — {$tenantName}";

        $urgencyColor = $isFinal ? '#C62828' : '#F9A825';
        $urgencyLabel = $isFinal ? 'DERNIÈRE CHANCE' : 'AVERTISSEMENT';

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <span style="font-size: 24px; font-weight: 700; color: #E91E63;">ILLIZEO</span>
    </div>

    <div style="background: {$urgencyColor}; color: #fff; padding: 16px 20px; border-radius: 8px; text-align: center; margin-bottom: 24px;">
        <div style="font-size: 12px; font-weight: 700; letter-spacing: 1px; margin-bottom: 4px;">{$urgencyLabel}</div>
        <div style="font-size: 18px; font-weight: 700;">Suppression de vos données le {$formattedDate}</div>
    </div>

    <p>Bonjour,</p>
    <p>Votre abonnement Illizeo pour l'espace <strong>{$tenantName}</strong> a expiré. Conformément à notre politique de conservation des données (RGPD), toutes vos données seront <strong>définitivement supprimées le {$formattedDate}</strong>.</p>

    <div style="background: #FFF3E0; border-left: 4px solid {$urgencyColor}; padding: 16px 20px; border-radius: 4px; margin: 20px 0;">
        <p style="margin: 0; font-weight: 600;">Données concernées :</p>
        <ul style="margin: 8px 0 0; padding-left: 20px;">
            <li>Collaborateurs et leurs dossiers RH</li>
            <li>Documents uploadés</li>
            <li>Parcours d'onboarding</li>
            <li>Contrats générés</li>
            <li>Messages et notifications</li>
            <li>Paramètres et configurations</li>
        </ul>
    </div>

    <p><strong>Cette action est irréversible.</strong> Une fois les données supprimées, elles ne pourront pas être récupérées.</p>

    <p>Pour conserver vos données, il vous suffit de <strong>réactiver votre abonnement</strong> avant le {$formattedDate} :</p>

    <div style="text-align: center; margin: 24px 0;">
        <a href="https://onboarding-illizeo.jcloud-ver-jpc.ik-server.com/{$tenantName}/abonnement"
           style="display: inline-block; padding: 14px 32px; background: #E91E63; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 15px;">
            Réactiver mon abonnement
        </a>
    </div>

    <p>Vous pouvez également exporter vos données avant la suppression depuis la page <strong>Données & RGPD</strong> de votre espace.</p>

    <p>Cordialement,<br>L'équipe Illizeo</p>

    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; text-align: center;">
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse<br>
        Cet email est envoyé conformément à l'article 17 du RGPD (droit à l'effacement).
    </div>
</div>
HTML;

        try {
            Mail::html($html, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
            $this->line("    Email sent to {$email}");
        } catch (\Exception $e) {
            $this->error("    Email failed: {$e->getMessage()}");
        }
    }

    /**
     * Delete all tenant data permanently.
     */
    private function deleteTenantData(Tenant $tenant): void
    {
        try {
            $tenantId = $tenant->id;

            // 1. Delete all subscriptions
            Subscription::where('tenant_id', $tenantId)->delete();

            // 2. Delete invoices
            \App\Models\Invoice::where('tenant_id', $tenantId)->delete();

            // 3. Drop the tenant database
            tenancy()->initialize($tenant);

            // Delete uploaded files
            $docPath = storage_path("app/private/documents");
            if (is_dir($docPath)) {
                $this->deleteDirectory($docPath);
            }

            // Delete invoice PDFs
            $invoicePath = storage_path("app/invoices");
            // Only delete PDFs for this tenant (filename contains tenant ID)

            tenancy()->end();

            // 4. Delete the tenant itself (cascades to tenant DB)
            $tenant->delete();

            $this->info("  [{$tenantId}] Tenant data deleted permanently");
            \Log::info("RGPD: Tenant {$tenantId} data deleted after 30-day retention period");

        } catch (\Exception $e) {
            $this->error("  [{$tenant->id}] Deletion failed: {$e->getMessage()}");
            \Log::error("RGPD deletion failed for tenant {$tenant->id}: {$e->getMessage()}");
        }
    }

    /**
     * Get the admin email for a tenant.
     */
    private function getTenantAdminEmail(string $tenantId): ?string
    {
        try {
            tenancy()->initialize(Tenant::find($tenantId));
            $email = \App\Models\CompanySetting::where('key', 'billing_contact_email')->value('value');
            if (!$email) {
                // Fallback: first admin user
                $admin = \App\Models\User::role(['super_admin', 'admin'])->first();
                $email = $admin?->email;
            }
            tenancy()->end();
            return $email;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
