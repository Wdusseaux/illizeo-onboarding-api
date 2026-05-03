<?php

namespace App\Mail;

use App\Mail\Concerns\HasIllizeoLogo;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoicePaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels, HasIllizeoLogo;

    public function __construct(
        public Invoice $invoice,
        public int $attemptNumber = 1,
        public bool $accessSuspended = false,
        public ?string $errorMessage = null,
        public ?string $portalUrl = null,
    ) {
        $this->embedIllizeoLogo();
    }

    public function envelope(): Envelope
    {
        $subject = $this->accessSuspended
            ? "⚠ Accès suspendu — Régulariser le paiement de la facture {$this->invoice->invoice_number}"
            : ($this->attemptNumber > 1
                ? "Échec de paiement (tentative {$this->attemptNumber}) — Facture {$this->invoice->invoice_number}"
                : "Échec de paiement — Facture {$this->invoice->invoice_number}");

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function buildHtml(): string
    {
        $snapshot = $this->invoice->billing_snapshot ?? [];
        $prenom = $snapshot['billing_contact_prenom'] ?? '';
        $nom = $snapshot['billing_contact_nom'] ?? '';
        $name = trim("{$prenom} {$nom}") ?: 'Client';
        $currency = strtoupper($this->invoice->currency ?? 'CHF');
        $montant = number_format((float) $this->invoice->montant_ttc, 2, '.', "'");
        $number = $this->invoice->invoice_number;
        $err = htmlspecialchars($this->errorMessage ?? 'Le paiement a été refusé par votre banque.');
        $portal = $this->portalUrl ?: 'https://onboarding.illizeo.com';
        $logoSrc = $this->illizeoLogoSrc();

        if ($this->accessSuspended) {
            return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <img src="{$logoSrc}" alt="Illizeo" style="height: 40px; width: auto; max-width: 200px;" />
    </div>
    <div style="background: #FFEBEE; border-left: 4px solid #C62828; padding: 16px 20px; margin-bottom: 24px; border-radius: 4px;">
        <strong style="color: #C62828; font-size: 16px;">⚠ Accès suspendu</strong><br>
        <span style="color: #5D4037; font-size: 13px;">Votre abonnement Illizeo est désormais en attente de régularisation.</span>
    </div>
    <p>Bonjour {$name},</p>
    <p>Après <strong>{$this->attemptNumber} tentatives de paiement infructueuses</strong>, l'accès à votre espace Illizeo a été suspendu.</p>
    <p>Pour réactiver votre compte, veuillez mettre à jour votre méthode de paiement et régulariser la facture <strong>{$number}</strong> ({$montant} {$currency}).</p>
    <div style="background: #f8f9fa; border-radius: 8px; padding: 16px 20px; margin: 20px 0;">
        <strong>Erreur signalée par votre banque :</strong><br>
        <span style="color: #5D4037;">{$err}</span>
    </div>
    <div style="text-align: center; margin: 30px 0;">
        <a href="{$portal}" style="display: inline-block; padding: 14px 28px; background: #E91E63; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600;">Régulariser maintenant</a>
    </div>
    <p style="font-size: 12px; color: #666;">Vos données restent en sécurité — l'accès sera rétabli dès le paiement validé. Si vous rencontrez des difficultés, contactez-nous à <a href="mailto:contact@illizeo.com">contact@illizeo.com</a>.</p>
    <p>Cordialement,<br>L'équipe Illizeo</p>
    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; text-align: center;">
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse · CHE-170.222.055
    </div>
</div>
HTML;
        }

        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <img src="{$logoSrc}" alt="Illizeo" style="height: 40px; width: auto; max-width: 200px;" />
    </div>
    <div style="background: #FFF8E1; border-left: 4px solid #FFC107; padding: 16px 20px; margin-bottom: 24px; border-radius: 4px;">
        <strong style="color: #5D4037; font-size: 14px;">Action requise — Paiement échoué</strong>
    </div>
    <p>Bonjour {$name},</p>
    <p>Le paiement de votre facture <strong>{$number}</strong> ({$montant} {$currency}) n'a pas pu être prélevé sur votre carte.</p>
    <div style="background: #f8f9fa; border-radius: 8px; padding: 16px 20px; margin: 20px 0;">
        <strong>Motif :</strong><br>
        <span style="color: #5D4037;">{$err}</span>
    </div>
    <p>Stripe va automatiquement retenter le prélèvement dans les prochains jours. Pour éviter toute interruption de service, nous vous invitons à vérifier votre méthode de paiement dès maintenant.</p>
    <div style="text-align: center; margin: 30px 0;">
        <a href="{$portal}" style="display: inline-block; padding: 14px 28px; background: #E91E63; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600;">Mettre à jour ma méthode de paiement</a>
    </div>
    <p style="font-size: 12px; color: #666;">Après plusieurs tentatives infructueuses, l'accès à votre compte sera temporairement suspendu jusqu'à régularisation.</p>
    <p>Cordialement,<br>L'équipe Illizeo</p>
    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; text-align: center;">
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse · CHE-170.222.055
    </div>
</div>
HTML;
    }
}
