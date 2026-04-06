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
    ];

    protected $casts = [
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'trial_ends_at' => 'date',
        'canceled_at' => 'datetime',
        'nombre_collaborateurs' => 'integer',
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
