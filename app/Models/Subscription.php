<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'stripe_subscription_id',
        'stripe_customer_id',
        'currency',
        'billing_cycle',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'canceled_at',
        'nombre_collaborateurs',
        // ── TVA / VAT ─────────────────────────────────────────
        'country', 'customer_type', 'vat_number',
        'vat_validation_status', 'vat_validated_at',
        'vat_rate', 'vat_amount_cents', 'amount_ht_cents', 'amount_ttc_cents',
        'vat_treatment',
        'cancel_at_period_end',
        'next_payment_amount_cents',
    ];

    protected $casts = [
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'trial_ends_at' => 'date',
        'canceled_at' => 'datetime',
        'nombre_collaborateurs' => 'integer',
        'vat_validated_at' => 'datetime',
        'vat_rate' => 'decimal:2',
        'vat_amount_cents' => 'integer',
        'amount_ht_cents' => 'integer',
        'amount_ttc_cents' => 'integer',
        'cancel_at_period_end' => 'boolean',
        'next_payment_amount_cents' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
