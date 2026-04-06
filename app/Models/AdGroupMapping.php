<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdGroupMapping extends Model
{
    protected $fillable = ['ad_group_id', 'ad_group_name', 'illizeo_role', 'auto_provision', 'auto_deprovision', 'actif'];

    protected function casts(): array
    {
        return ['auto_provision' => 'boolean', 'auto_deprovision' => 'boolean', 'actif' => 'boolean'];
    }
}
