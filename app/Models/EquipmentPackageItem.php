<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentPackageItem extends Model
{
    protected $fillable = ['equipment_package_id', 'equipment_type_id', 'quantite', 'notes'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(EquipmentPackage::class, 'equipment_package_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }
}
