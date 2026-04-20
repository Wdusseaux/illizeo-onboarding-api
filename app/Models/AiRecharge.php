<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRecharge extends Model
{
    protected $fillable = [
        'amount_chf', 'credits_added', 'trigger', 'status',
        'stripe_payment_intent_id', 'invoice_number', 'error',
    ];

    protected $casts = [
        'amount_chf' => 'decimal:2',
        'credits_added' => 'integer',
    ];
}
