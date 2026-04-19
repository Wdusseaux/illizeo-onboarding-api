<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string $pdfPath,
        public bool $isReminder = false,
        public int $reminderDay = 0,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isReminder
            ? "Rappel : Facture {$this->invoice->invoice_number} — échéance dans " . max(0, 30 - $this->reminderDay) . " jours"
            : "Facture {$this->invoice->invoice_number} — Illizeo";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->pdfPath)
                ->as($this->invoice->invoice_number . '.pdf')
                ->withMime('application/pdf'),
        ];
    }

    private function buildHtml(): string
    {
        $snapshot = $this->invoice->billing_snapshot ?? [];
        $prenom = $snapshot['billing_contact_prenom'] ?? '';
        $nom = $snapshot['billing_contact_nom'] ?? '';
        $name = trim("{$prenom} {$nom}") ?: 'Client';
        $montant = number_format((float) $this->invoice->montant_ttc, 2, '.', "'");
        $echeance = \Carbon\Carbon::parse($this->invoice->date_echeance)->format('d/m/Y');
        $number = $this->invoice->invoice_number;

        if ($this->isReminder) {
            $daysLeft = max(0, 30 - $this->reminderDay);
            return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <span style="font-size: 24px; font-weight: 700; color: #E91E63;">ILLIZEO</span>
    </div>
    <p>Bonjour {$name},</p>
    <p>Nous vous rappelons que la facture <strong>{$number}</strong> d'un montant de <strong>{$montant} CHF</strong> arrive à échéance le <strong>{$echeance}</strong> (dans {$daysLeft} jours).</p>
    <p>Si le paiement a déjà été effectué, veuillez ignorer ce rappel.</p>
    <div style="background: #f8f9fa; border-radius: 8px; padding: 16px 20px; margin: 20px 0;">
        <strong>Coordonnées bancaires :</strong><br>
        Bénéficiaire : Illizeo Sàrl<br>
        IBAN : <strong>CH59 0022 8228 1610 9501 U</strong><br>
        BIC : UBSWCHZH80A<br>
        Référence : <strong>{$number}</strong>
    </div>
    <p>La facture est jointe à cet email au format PDF.</p>
    <p>Cordialement,<br>L'équipe Illizeo</p>
    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; text-align: center;">
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse
    </div>
</div>
HTML;
        }

        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <span style="font-size: 24px; font-weight: 700; color: #E91E63;">ILLIZEO</span>
    </div>
    <p>Bonjour {$name},</p>
    <p>Veuillez trouver ci-joint votre facture <strong>{$number}</strong> d'un montant de <strong>{$montant} CHF</strong>.</p>
    <div style="background: #f8f9fa; border-radius: 8px; padding: 16px 20px; margin: 20px 0;">
        <table style="font-size: 13px; width: 100%;">
            <tr><td style="color: #666; padding: 4px 0;">Numéro</td><td style="text-align: right;"><strong>{$number}</strong></td></tr>
            <tr><td style="color: #666; padding: 4px 0;">Montant TTC</td><td style="text-align: right;"><strong>{$montant} CHF</strong></td></tr>
            <tr><td style="color: #666; padding: 4px 0;">Échéance</td><td style="text-align: right;">{$echeance}</td></tr>
        </table>
    </div>
    <p>La facture détaillée est jointe au format PDF.</p>
    <p>Cordialement,<br>L'équipe Illizeo</p>
    <div style="margin-top: 30px; padding-top: 16px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; text-align: center;">
        Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse
    </div>
</div>
HTML;
    }
}
