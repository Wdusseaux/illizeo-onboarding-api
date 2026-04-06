<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'stripe_invoice_id',
        'montant',
        'currency',
        'status',
        'date_emission',
        'date_echeance',
        'pdf_url',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'date_emission' => 'date',
        'date_echeance' => 'date',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
