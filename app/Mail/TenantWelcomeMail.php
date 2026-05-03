<?php

namespace App\Mail;

use App\Mail\Concerns\HasIllizeoLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantWelcomeMail extends Mailable
{
    use Queueable, SerializesModels, HasIllizeoLogo;

    public function __construct(
        public string $tenantId,
        public string $companyName,
        public string $adminName,
        public string $adminEmail,
        public string $tenantUrl,
    ) {
        $this->embedIllizeoLogo();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Bienvenue sur Illizeo, {$this->adminName} ! 🎉");
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function buildHtml(): string
    {
        $name = htmlspecialchars($this->adminName);
        $company = htmlspecialchars($this->companyName);
        $url = htmlspecialchars($this->tenantUrl);
        $tenantId = htmlspecialchars($this->tenantId);
        $logoSrc = $this->illizeoLogoSrc();

        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <img src="{$logoSrc}" alt="Illizeo" style="height: 44px; width: auto; max-width: 220px;" />
    </div>

    <div style="background: linear-gradient(135deg, #E91E63 0%, #9C27B0 100%); border-radius: 16px; padding: 32px 28px; color: #fff; text-align: center; margin-bottom: 28px;">
        <div style="font-size: 32px; margin-bottom: 8px;">🎉</div>
        <div style="font-size: 22px; font-weight: 700; margin-bottom: 6px;">Bienvenue sur Illizeo !</div>
        <div style="font-size: 14px; opacity: 0.95;">Votre essai gratuit de 14 jours commence maintenant</div>
    </div>

    <p style="font-size: 15px; color: #333;">Bonjour <strong>{$name}</strong>,</p>
    <p style="font-size: 14px; color: #555; line-height: 1.6;">
        Votre espace <strong>{$company}</strong> est prêt sur Illizeo.
        Vous bénéficiez de 14 jours d'essai gratuits pour découvrir toutes les fonctionnalités, sans carte bancaire.
    </p>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{$url}" style="display: inline-block; padding: 14px 32px; background: #E91E63; color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 15px;">Accéder à mon espace →</a>
    </div>

    <div style="background: #f8f9fa; border-radius: 12px; padding: 20px 24px; margin: 24px 0;">
        <div style="font-size: 13px; font-weight: 700; color: #1a1a2e; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px;">Pour bien démarrer</div>
        <ul style="font-size: 13px; color: #555; line-height: 1.8; padding-left: 20px; margin: 0;">
            <li><strong>Configuration en 5 minutes</strong> — un assistant vous guide pour personnaliser votre espace (logo, couleurs, équipe)</li>
            <li><strong>Importez vos premiers collaborateurs</strong> — manuellement ou via CSV</li>
            <li><strong>Choisissez un parcours d'onboarding</strong> — modèles prêts à l'emploi (Standard, Tech, Commercial, etc.)</li>
            <li><strong>Activez vos intégrations</strong> — Microsoft 365, Slack, DocuSign, BambooHR…</li>
        </ul>
    </div>

    <div style="background: #FFF8E1; border-left: 4px solid #FFC107; padding: 14px 18px; margin: 20px 0; border-radius: 4px;">
        <div style="font-size: 12px; color: #5D4037;">
            <strong>💡 Astuce :</strong> Vous avez 14 jours pour tester toutes les fonctionnalités. Aucune carte bancaire requise.
            Si vous décidez de continuer, choisissez votre plan dans <em>Paramètres → Abonnement</em>.
        </div>
    </div>

    <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid #eee;">
        <div style="font-size: 13px; font-weight: 700; color: #1a1a2e; margin-bottom: 10px;">Vos identifiants</div>
        <table style="font-size: 13px; color: #555; width: 100%;">
            <tr><td style="color: #888; padding: 4px 0; width: 130px;">Espace</td><td><strong>{$tenantId}</strong></td></tr>
            <tr><td style="color: #888; padding: 4px 0;">URL</td><td><a href="{$url}" style="color: #E91E63; text-decoration: none;">{$url}</a></td></tr>
            <tr><td style="color: #888; padding: 4px 0;">Email admin</td><td>{$this->adminEmail}</td></tr>
        </table>
    </div>

    <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; font-size: 12px; color: #888; text-align: center; line-height: 1.6;">
        Besoin d'aide ? Répondez à cet email ou contactez-nous à <a href="mailto:contact@illizeo.com" style="color: #E91E63;">contact@illizeo.com</a><br>
        <br>
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse
    </div>
</div>
HTML;
    }
}
