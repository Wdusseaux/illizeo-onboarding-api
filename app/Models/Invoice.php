<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'invoice_number',
        'tenant_id',
        'subscription_id',
        'plan_id',
        'stripe_payment_intent_id',
        'stripe_invoice_id',
        'montant_ht',
        'taux_tva',
        'montant_tva',
        'montant_ttc',
        'prorata_credit',
        'currency',
        'payment_method',
        'nombre_collaborateurs',
        'billing_cycle',
        'period_start',
        'period_end',
        'status',
        'date_emission',
        'date_echeance',
        'paid_at',
        'payment_attempts',
        'last_payment_attempt',
        'payment_error',
        'pdf_path',
        'billing_snapshot',
        'line_items',
    ];

    protected $casts = [
        'montant_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
        'prorata_credit' => 'decimal:2',
        'nombre_collaborateurs' => 'integer',
        'payment_attempts' => 'integer',
        'paid_at' => 'datetime',
        'last_payment_attempt' => 'datetime',
        'billing_snapshot' => 'array',
        'line_items' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Generate next invoice number: INV-YYYYMM-XXXX
     */
    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';
        $lastInvoice = static::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            return $prefix . str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
        }

        return $prefix . '0001';
    }
}
