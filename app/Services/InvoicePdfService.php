<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscription;
use Dompdf\Dompdf;
use Dompdf\Options;

class InvoicePdfService
{
    /**
     * Generates a Swiss-VAT-compliant invoice PDF.
     *
     * Compliance:
     * - LTVA art. 26 (mandatory invoice mentions: issuer, customer, date, services, HT/TTC, VAT rate/amount)
     * - LTVA art. 70 (10-year retention)
     * - Code des obligations art. 957 (CHF equivalent for foreign-currency invoices)
     * - Directive 2006/112/CE art. 196 (EU reverse charge mention)
     */
    public function generate(Invoice $invoice): string
    {
        $plan = $invoice->plan;
        $snapshot = $invoice->billing_snapshot ?? [];
        $subscription = $invoice->subscription_id
            ? Subscription::find($invoice->subscription_id)
            : null;

        $html = $this->buildHtml($invoice, $plan, $subscription, $snapshot);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

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

    private function buildHtml(Invoice $invoice, $plan, ?Subscription $subscription, array $snapshot): string
    {
        $contactPrenom = $snapshot['billing_contact_prenom'] ?? '';
        $contactNom = $snapshot['billing_contact_nom'] ?? '';
        $contactEmail = $snapshot['billing_contact_email'] ?? '';
        $company = $snapshot['billing_company'] ?? '';
        $vat = $snapshot['billing_vat'] ?? ($subscription->vat_number ?? '');
        $rue = $snapshot['billing_rue'] ?? '';
        $numero = $snapshot['billing_numero'] ?? '';
        $cp = $snapshot['billing_code_postal'] ?? '';
        $ville = $snapshot['billing_ville'] ?? '';
        $canton = $snapshot['billing_canton'] ?? '';
        $pays = $snapshot['billing_contact_pays']
            ?? $snapshot['billing_pays']
            ?? ($subscription->country ?? 'Suisse');

        $clientAddress = implode('<br>', array_filter([
            htmlspecialchars($company),
            htmlspecialchars(trim("{$contactPrenom} {$contactNom}")),
            htmlspecialchars(trim("{$rue} {$numero}")),
            htmlspecialchars(trim("{$cp} {$ville}")),
            $canton ? htmlspecialchars("{$canton}, {$pays}") : htmlspecialchars($pays),
        ]));

        $planName = $plan->nom ?? 'Abonnement';
        $isAi = $plan->addon_type === 'ai';
        $billingLabel = $invoice->billing_cycle === 'yearly' ? 'annuelle' : 'mensuelle';

        $currency = strtoupper($invoice->currency ?? 'CHF');
        $currencySymbol = $this->currencySymbol($currency);

        // Treatment + legal mention
        $treatment = $subscription->vat_treatment ?? 'ch_standard';
        $legalMention = $this->legalMention($treatment, $vat);

        // Line item description
        if ($isAi) {
            $lineDesc = "{$planName} — Facturation {$billingLabel} (prix fixe)";
        } else {
            $unitPrice = (float) $invoice->montant_ht / max(1, $invoice->nombre_collaborateurs);
            $lineDesc = "{$planName} — {$invoice->nombre_collaborateurs} employé(s) × " .
                $this->formatMoney($unitPrice) . " {$currency} / employé / mois";
            if ($invoice->billing_cycle === 'yearly') {
                $lineDesc .= ' (réduction annuelle -10%)';
            }
        }

        $statusLabel = match ($invoice->status) {
            'paid' => 'PAYÉE',
            'sent' => 'EN ATTENTE',
            'draft' => 'BROUILLON',
            'failed' => 'ÉCHEC PAIEMENT',
            default => strtoupper($invoice->status),
        };

        $paymentMethodLabel = match ($invoice->payment_method) {
            'stripe' => 'Carte bancaire (Stripe)',
            'sepa' => 'Prélèvement SEPA',
            'invoice' => 'Virement bancaire',
            default => $invoice->payment_method,
        };

        $periodStart = \Carbon\Carbon::parse($invoice->period_start)->format('d/m/Y');
        $periodEnd = \Carbon\Carbon::parse($invoice->period_end)->format('d/m/Y');
        $dateEmission = \Carbon\Carbon::parse($invoice->date_emission)->format('d/m/Y');
        $dateEcheance = \Carbon\Carbon::parse($invoice->date_echeance)->format('d/m/Y');

        // Logo — base64 data URI for maximum DomPDF compatibility
        // (file paths and remote URLs sometimes fail silently in DomPDF)
        $logoFilePath = public_path('build/illizeo-Logo-site.png');
        $logoSrc = '';
        if (file_exists($logoFilePath)) {
            $logoData = @file_get_contents($logoFilePath);
            if ($logoData !== false) {
                $logoSrc = 'data:image/png;base64,' . base64_encode($logoData);
            }
        }

        // CHF equivalent for foreign-currency invoices (Code des obligations art. 957)
        $chfEquivalentBlock = '';
        if ($currency !== 'CHF') {
            $chfRate = $this->fetchChfRate($currency);
            if ($chfRate > 0) {
                $ttcChf = (float) $invoice->montant_ttc * $chfRate;
                $htChf = (float) $invoice->montant_ht * $chfRate;
                $tvaChf = (float) $invoice->montant_tva * $chfRate;
                $chfEquivalentBlock = '<div style="margin-top: 12px; font-size: 10px; color: #666; font-style: italic;">'
                    . 'Équivalent CHF (taux ' . number_format($chfRate, 4, '.', "'") . ' au ' . $dateEmission . ') : '
                    . 'HT ' . $this->formatMoney($htChf) . ' CHF · '
                    . 'TVA ' . $this->formatMoney($tvaChf) . ' CHF · '
                    . 'TTC ' . $this->formatMoney($ttcChf) . ' CHF'
                    . '</div>';
            }
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #333; margin: 40px; }
    .logo { font-size: 24px; font-weight: 700; color: #E91E63; }
    .logo-sub { font-size: 9px; color: #888; }
    .invoice-title { font-size: 28px; font-weight: 700; color: #1a1a2e; margin-bottom: 5px; }
    .invoice-number { font-size: 14px; color: #666; }
    .status { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 10px; font-weight: 700; }
    .status-paid { background: #E8F5E9; color: #2E7D32; }
    .status-sent { background: #E3F2FD; color: #1565C0; }
    .status-draft { background: #F5F5F5; color: #666; }
    .status-failed { background: #FFEBEE; color: #C62828; }
    .address-label { font-size: 9px; font-weight: 700; color: #888; text-transform: uppercase; margin-bottom: 8px; }
    .meta-table { width: 100%; margin-bottom: 30px; }
    .meta-table td { padding: 6px 12px; font-size: 11px; }
    .meta-table .label { color: #888; width: 180px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    table.items th { background: #f8f9fa; padding: 10px 12px; text-align: left; font-size: 10px; font-weight: 700; color: #666; text-transform: uppercase; border-bottom: 2px solid #e0e0e0; }
    table.items td { padding: 10px 12px; border-bottom: 1px solid #eee; }
    table.items .amount { text-align: right; }
    .totals { margin-left: auto; width: 320px; }
    .totals table { width: 100%; }
    .totals td { padding: 6px 0; }
    .totals .label { color: #666; }
    .totals .value { text-align: right; font-weight: 600; }
    .totals .total-row td { border-top: 2px solid #333; padding-top: 10px; font-size: 16px; font-weight: 700; }
    .legal-mention { background: #FFF8E1; border-left: 4px solid #FFC107; padding: 12px 16px; margin-top: 24px; font-size: 10px; color: #5D4037; }
    .bank-info { background: #f8f9fa; border-radius: 8px; padding: 16px 20px; margin-top: 24px; }
    .bank-title { font-weight: 700; margin-bottom: 8px; color: #1a1a2e; }
    .footer { margin-top: 40px; text-align: center; font-size: 9px; color: #aaa; border-top: 1px solid #eee; padding-top: 16px; }
    .retention { margin-top: 16px; font-size: 8px; color: #999; text-align: center; }
</style>
</head>
<body>

<table width="100%" style="margin-bottom: 30px;">
<tr>
    <td>
HTML;
        $html .= $logoSrc
            ? '<img src="' . $logoSrc . '" alt="Illizeo" style="height: 36px; width: auto;" />'
            : '<span class="logo">ILLIZEO</span><br><span class="logo-sub">THE ALL-IN-ONE HR SOLUTION</span>';
        $html .= <<<HTML
    </td>
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
        <div style="margin-top: 6px;">N° TVA : <strong>CHE-170.222.055</strong></div>
    </td>
    <td width="50%" style="vertical-align: top;">
        <div class="address-label">FACTURER À</div>
        <div>{$clientAddress}</div>
HTML;

        if ($vat) {
            $html .= "<div style='margin-top: 6px;'>N° TVA : <strong>" . htmlspecialchars($vat) . "</strong></div>";
        }
        if ($contactEmail) {
            $html .= "<div style='margin-top: 4px;'>" . htmlspecialchars($contactEmail) . "</div>";
        }

        $html .= <<<HTML
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
<tr>
    <td class="label">Devise</td><td colspan="3"><strong>{$currency}</strong> ({$currencySymbol})</td>
</tr>
</table>

<table class="items">
<thead>
<tr>
    <th>Description</th>
    <th>Qté</th>
    <th class="amount">Prix unitaire</th>
    <th class="amount">Montant HT</th>
</tr>
</thead>
<tbody>
HTML;

        // Render line items
        $lineItems = $invoice->line_items;
        if (!empty($lineItems) && is_array($lineItems)) {
            foreach ($lineItems as $item) {
                $desc = htmlspecialchars($item['description'] ?? '');
                $qty = $item['quantity'] ?? 1;
                $unitPrice = $this->formatMoney((float) ($item['unit_price'] ?? 0));
                $amount = $this->formatMoney((float) ($item['amount'] ?? 0));
                $html .= "<tr><td>{$desc}</td><td>{$qty}</td><td class='amount'>{$unitPrice} {$currency}</td><td class='amount'>{$amount} {$currency}</td></tr>";
            }
        } else {
            $qty = $isAi ? 1 : $invoice->nombre_collaborateurs;
            $unitPriceFallback = $this->formatMoney((float) $invoice->montant_ht / max(1, $qty));
            $html .= "<tr><td>" . htmlspecialchars($lineDesc) . "</td><td>{$qty}</td><td class='amount'>{$unitPriceFallback} {$currency}</td><td class='amount'>{$this->formatMoney($invoice->montant_ht)} {$currency}</td></tr>";
        }

        $html .= <<<HTML
</tbody>
</table>

<div class="totals">
<table>
<tr><td class="label">Sous-total HT</td><td class="value">{$this->formatMoney($invoice->montant_ht)} {$currency}</td></tr>
HTML;

        if ((float) $invoice->taux_tva > 0) {
            $tauxFmt = rtrim(rtrim(number_format((float) $invoice->taux_tva, 2, '.', ''), '0'), '.');
            $html .= "<tr><td class='label'>TVA ({$tauxFmt}%)</td><td class='value'>{$this->formatMoney($invoice->montant_tva)} {$currency}</td></tr>";
        } else {
            $html .= "<tr><td class='label'>TVA</td><td class='value'>0.00 {$currency}</td></tr>";
        }

        if ((float) $invoice->prorata_credit > 0) {
            $html .= "<tr><td class='label'>Crédit prorata</td><td class='value' style='color: #2E7D32;'>-{$this->formatMoney($invoice->prorata_credit)} {$currency}</td></tr>";
        }

        $html .= <<<HTML
<tr class="total-row"><td class="label">TOTAL TTC</td><td class="value">{$this->formatMoney($invoice->montant_ttc)} {$currency}</td></tr>
</table>
{$chfEquivalentBlock}
</div>

<div style="clear: both;"></div>

<div class="legal-mention">
    <strong>Mention légale TVA :</strong><br>
    {$legalMention}
</div>
HTML;

        if ($invoice->payment_method === 'invoice') {
            // Pick UBS account based on currency
            $bankBlock = $this->bankBlock($currency, $invoice->invoice_number);
            $html .= $bankBlock;
        }

        $html .= <<<HTML
<div class="footer">
    Illizeo Sàrl · Chemin des Saules 12a · 1260 Nyon · Suisse · N° TVA CHE-170.222.055<br>
    www.illizeo.com · contact@illizeo.com
</div>
<div class="retention">
    Document conservé conformément à l'art. 70 LTVA (durée légale de conservation : 10 ans).
</div>

</body>
</html>
HTML;

        return $html;
    }

    private function legalMention(string $treatment, ?string $clientVat): string
    {
        return match ($treatment) {
            'ch_standard' => 'TVA suisse au taux normal de 8,10% (LTVA art. 25 al. 1).',
            'eu_reverse_charge' => 'TVA non applicable — autoliquidation par le preneur (art. 196 directive 2006/112/CE). '
                . 'N° TVA du preneur : ' . htmlspecialchars($clientVat ?: 'non fourni') . '. '
                . 'Reverse charge — VAT to be accounted for by the recipient.',
            'eu_b2c' => 'TVA suisse non applicable — prestation B2C hors Suisse (LTVA art. 8 al. 1).',
            'export' => 'Export de services hors Union européenne — hors champ de la TVA suisse (LTVA art. 8 al. 1).',
            'tenant_not_vat_registered' => 'Émetteur non assujetti à la TVA selon LTVA art. 10 al. 2 (chiffre d\'affaires inférieur au seuil).',
            default => 'TVA suisse au taux normal de 8,10% (LTVA art. 25 al. 1).',
        };
    }

    private function bankBlock(string $currency, string $invoiceNumber): string
    {
        // Multi-currency UBS settlement: CHF account for CHF, EUR account for EUR, CHF for others
        if ($currency === 'EUR') {
            return <<<HTML
<div class="bank-info">
    <div class="bank-title">Coordonnées bancaires (compte EUR)</div>
    <table style="font-size: 11px;">
    <tr><td style="color: #666; width: 120px;">Bénéficiaire</td><td><strong>Illizeo Sàrl</strong></td></tr>
    <tr><td style="color: #666;">IBAN (EUR)</td><td><strong style="letter-spacing: 0.5px;">CH59 0022 8228 1610 9501 U</strong></td></tr>
    <tr><td style="color: #666;">BIC / SWIFT</td><td>UBSWCHZH80A</td></tr>
    <tr><td style="color: #666;">Banque</td><td>UBS Switzerland AG, Bahnhofstrasse 45, 8048 Zürich</td></tr>
    <tr><td style="color: #666;">Référence</td><td><strong>{$invoiceNumber}</strong></td></tr>
    </table>
</div>
HTML;
        }

        return <<<HTML
<div class="bank-info">
    <div class="bank-title">Coordonnées bancaires (compte CHF)</div>
    <table style="font-size: 11px;">
    <tr><td style="color: #666; width: 120px;">Bénéficiaire</td><td><strong>Illizeo Sàrl</strong></td></tr>
    <tr><td style="color: #666;">IBAN (CHF)</td><td><strong style="letter-spacing: 0.5px;">CH59 0022 8228 1610 9501 U</strong></td></tr>
    <tr><td style="color: #666;">BIC / SWIFT</td><td>UBSWCHZH80A</td></tr>
    <tr><td style="color: #666;">Banque</td><td>UBS Switzerland AG, Bahnhofstrasse 45, 8048 Zürich</td></tr>
    <tr><td style="color: #666;">Référence</td><td><strong>{$invoiceNumber}</strong></td></tr>
    </table>
</div>
HTML;
    }

    private function currencySymbol(string $currency): string
    {
        return match ($currency) {
            'CHF' => 'Fr.',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
            default => $currency,
        };
    }

    /**
     * Fetch CHF→target rate, then invert to get target→CHF.
     * Uses the cached exchange_rates_cache table populated by ExchangeRateService.
     */
    private function fetchChfRate(string $currency): float
    {
        try {
            $row = \DB::connection('central')
                ->table('exchange_rates_cache')
                ->where('base_currency', 'CHF')
                ->where('target_currency', strtoupper($currency))
                ->first();

            if ($row && $row->rate > 0) {
                return 1 / (float) $row->rate;
            }
        } catch (\Throwable $e) {
            // Silent fallback — invoice still issues without CHF equivalent
        }
        return 0.0;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', "'");
    }
}
