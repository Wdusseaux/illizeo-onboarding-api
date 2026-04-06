<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanModule extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'plan_id',
        'module',
        'actif',
        'config',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'config' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
