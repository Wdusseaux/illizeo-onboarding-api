<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentPackage extends Model
{
    protected $fillable = ['nom', 'description', 'icon', 'couleur', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EquipmentPackageItem::class);
    }
}
