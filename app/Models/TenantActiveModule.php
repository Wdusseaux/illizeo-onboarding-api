<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantActiveModule extends Model
{
    protected $fillable = [
        'module',
        'source_plan_id',
        'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'source_plan_id' => 'integer',
    ];
}
