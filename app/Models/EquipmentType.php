<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentType extends Model
{
    protected $fillable = ['nom', 'icon', 'categorie', 'description', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
