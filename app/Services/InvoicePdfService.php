<?php

namespace App\Services;

use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

class InvoicePdfService
{
    public function generate(Invoice $invoice): string
    {
        $plan = $invoice->plan;
        $snapshot = $invoice->billing_snapshot ?? [];

        $html = $this->buildHtml($invoice, $plan, $snapshot);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Save PDF
        $dir = storage_path('app/invoices');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = $invoice->invoice_number . '.pdf';
        $path = $dir . '/' . $filename;
        file_put_contents($path, $dompdf->output());

        $invoice->update(['pdf_path' => 'invoices/' . $filename]);

        return $path;
    }

    private function buildHtml(Invoice $invoice, $plan, array $snapshot): string
    {
        $contactPrenom = $snapshot['billing_contact_prenom'] ?? '';
        $contactNom = $snapshot['billing_contact_nom'] ?? '';
        $contactEmail = $snapshot['billing_contact_email'] ?? '';
        $company = $snapshot['billing_company'] ?? '';
        $vat = $snapshot['billing_vat'] ?? '';
        $rue = $snapshot['billing_rue'] ?? '';
        $numero = $snapshot['billing_numero'] ?? '';
        $cp = $snapshot['billing_code_postal'] ?? '';
        $ville = $snapshot['billing_ville'] ?? '';
        $canton = $snapshot['billing_canton'] ?? '';
        $pays = $snapshot['billing_contact_pays'] ?? $snapshot['billing_pays'] ?? 'Suisse';

        $clientAddress = implode('<br>', array_filter([
            $company,
            trim("{$contactPrenom} {$contactNom}"),
            trim("{$rue} {$numero}"),
            trim("{$cp} {$ville}"),
            $canton ? "{$canton}, {$pays}" : $pays,
        ]));

        $planName = $plan->nom ?? 'Abonnement';
        $isAi = $plan->addon_type === 'ai';
        $billingLabel = $invoice->billing_cycle === 'yearly' ? 'annuelle' : 'mensuelle';

        // Line item description
        if ($isAi) {
            $lineDesc = "{$planName} — Facturation {$billingLabel} (prix fixe)";
        } else {
            $lineDesc = "{$planName} — {$invoice->nombre_collaborateurs} employé(s) × " .
                number_format((float)$invoice->montant_ht / max(1, $invoice->nombre_collaborateurs), 2) .
                " CHF / employé / mois";
            if ($invoice->billing_cycle === 'yearly') {
                $lineDesc .= ' (réduction annuelle -10%)';
            }
        }

        $statusLabel = match($invoice->status) {
            'paid' => 'PAYÉE',
            'sent' => 'EN ATTENTE',
            'draft' => 'BROUILLON',
            'failed' => 'ÉCHEC PAIEMENT',
            default => strtoupper($invoice->status),
        };

        $paymentMethodLabel = match($invoice->payment_method) {
            'stripe' => 'Carte bancaire (Stripe)',
            'sepa' => 'Prélèvement SEPA',
            'invoice' => 'Virement bancaire',
            default => $invoice->payment_method,
        };

        $periodStart = \Carbon\Carbon::parse($invoice->period_start)->format('d/m/Y');
        $periodEnd = \Carbon\Carbon::parse($invoice->period_end)->format('d/m/Y');
        $dateEmission = \Carbon\Carbon::parse($invoice->date_emission)->format('d/m/Y');
        $dateEcheance = \Carbon\Carbon::parse($invoice->date_echeance)->format('d/m/Y');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #333; margin: 40px; }
    .header { display: flex; justify-content: space-between; margin-bottom: 40px; }
    .logo { font-size: 24px; font-weight: 700; color: #E91E63; }
    .logo-sub { font-size: 9px; color: #888; }
    .invoice-title { font-size: 28px; font-weight: 700; color: #1a1a2e; margin-bottom: 5px; }
    .invoice-number { font-size: 14px; color: #666; }
    .status { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 10px; font-weight: 700; }
    .status-paid { background: #E8F5E9; color: #2E7D32; }
    .status-sent { background: #E3F2FD; color: #1565C0; }
    .status-draft { background: #F5F5F5; color: #666; }
    .status-failed { background: #FFEBEE; color: #C62828; }
    .addresses { display: flex; justify-content: space-between; margin-bottom: 30px; }
    .address-block { width: 48%; }
    .address-label { font-size: 9px; font-weight: 700; color: #888; text-transform: uppercase; margin-bottom: 8px; }
    .meta-table { width: 100%; margin-bottom: 30px; }
    .meta-table td { padding: 6px 12px; font-size: 11px; }
    .meta-table .label { color: #888; width: 180px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    table.items th { background: #f8f9fa; padding: 10px 12px; text-align: left; font-size: 10px; font-weight: 700; color: #666; text-transform: uppercase; border-bottom: 2px solid #e0e0e0; }
    table.items td { padding: 10px 12px; border-bottom: 1px solid #eee; }
    table.items .amount { text-align: right; }
    .totals { margin-left: auto; width: 280px; }
    .totals table { width: 100%; }
    .totals td { padding: 6px 0; }
    .totals .label { color: #666; }
    .totals .value { text-align: right; font-weight: 600; }
    .totals .total-row td { border-top: 2px solid #333; padding-top: 10px; font-size: 16px; font-weight: 700; }
    .bank-info { background: #f8f9fa; border-radius: 8px; padding: 16px 20px; margin-top: 30px; }
    .bank-title { font-weight: 700; margin-bottom: 8px; color: #1a1a2e; }
    .footer { margin-top: 40px; text-align: center; font-size: 9px; color: #aaa; border-top: 1px solid #eee; padding-top: 16px; }
</style>
</head>
<body>

<table width="100%" style="margin-bottom: 30px;">
<tr>
    <td><span class="logo">ILLIZEO</span><br><span class="logo-sub">THE ALL-IN-ONE HR SOLUTION</span></td>
    <td style="text-align: right;">
        <div class="invoice-title">FACTURE</div>
        <div class="invoice-number">{$invoice->invoice_number}</div>
        <div style="margin-top: 8px;">
            <span class="status status-{$invoice->status}">{$statusLabel}</span>
        </div>
    </td>
</tr>
</table>

<table width="100%" style="margin-bottom: 30px;">
<tr>
    <td width="50%" style="vertical-align: top;">
        <div class="address-label">ÉMETTEUR</div>
        <div><strong>Illizeo Sàrl</strong></div>
        <div>Chemin des Saules 12a</div>
        <div>1260 Nyon, Suisse</div>
        <div style="margin-top: 6px;">TVA: CHE-170.222.055</div>
    </td>
    <td width="50%" style="vertical-align: top;">
        <div class="address-label">FACTURER À</div>
        <div>{$clientAddress}</div>
        {$vat ? "<div style='margin-top: 6px;'>TVA: {$vat}</div>" : ""}
        <div style="margin-top: 4px;">{$contactEmail}</div>
    </td>
</tr>
</table>

<table class="meta-table" style="background: #f8f9fa; border-radius: 6px;">
<tr>
    <td class="label">Date d'émission</td><td>{$dateEmission}</td>
    <td class="label">Date d'échéance</td><td>{$dateEcheance}</td>
</tr>
<tr>
    <td class="label">Période</td><td>{$periodStart} — {$periodEnd}</td>
    <td class="label">Mode de paiement</td><td>{$paymentMethodLabel}</td>
</tr>
</table>

<table class="items">
<thead>
<tr>
    <th>Description</th>
    <th>Qté</th>
    <th class="amount">Prix unitaire</th>
    <th class="amount">Montant</th>
</tr>
</thead>
<tbody>
<tr>
    <td>{$lineDesc}</td>
    <td>{$invoice->nombre_collaborateurs}</td>
    <td class="amount">{$this->formatMoney((float)$invoice->montant_ht / max(1, $isAi ? 1 : $invoice->nombre_collaborateurs))} CHF</td>
    <td class="amount">{$this->formatMoney($invoice->montant_ht)} CHF</td>
</tr>
</tbody>
</table>

<div class="totals">
<table>
<tr><td class="label">Sous-total HT</td><td class="value">{$this->formatMoney($invoice->montant_ht)} CHF</td></tr>
HTML;

        $html .= $invoice->taux_tva > 0
            ? "<tr><td class='label'>TVA ({$invoice->taux_tva}%)</td><td class='value'>{$this->formatMoney($invoice->montant_tva)} CHF</td></tr>"
            : "<tr><td class='label'>TVA</td><td class='value'>Non applicable</td></tr>";

        if ($invoice->prorata_credit > 0) {
            $html .= "<tr><td class='label'>Crédit prorata</td><td class='value' style='color: #2E7D32;'>-{$this->formatMoney($invoice->prorata_credit)} CHF</td></tr>";
        }

        $html .= <<<HTML
<tr class="total-row"><td class="label">TOTAL TTC</td><td class="value">{$this->formatMoney($invoice->montant_ttc)} CHF</td></tr>
</table>
</div>
HTML;

        if ($invoice->payment_method === 'invoice') {
            $html .= <<<HTML
<div class="bank-info">
    <div class="bank-title">Coordonnées bancaires pour le virement</div>
    <table style="font-size: 11px;">
    <tr><td style="color: #666; width: 120px;">Bénéficiaire</td><td><strong>Illizeo Sàrl</strong></td></tr>
    <tr><td style="color: #666;">IBAN</td><td><strong style="letter-spacing: 0.5px;">CH59 0022 8228 1610 9501 U</strong></td></tr>
    <tr><td style="color: #666;">BIC / SWIFT</td><td>UBSWCHZH80A</td></tr>
    <tr><td style="color: #666;">Banque</td><td>UBS Switzerland AG, Bahnhofstrasse 45, 8048 Zürich</td></tr>
    <tr><td style="color: #666;">Référence</td><td><strong>{$invoice->invoice_number}</strong></td></tr>
    </table>
</div>
HTML;
        }

        $html .= <<<HTML
<div class="footer">
    Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse · TVA CHE-170.222.055<br>
    www.illizeo.com · contact@illizeo.com
</div>

</body>
</html>
HTML;

        return $html;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', "'");
    }
}
