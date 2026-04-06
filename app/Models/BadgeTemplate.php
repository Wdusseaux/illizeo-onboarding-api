<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BadgeTemplate extends Model
{
    protected $fillable = [
        'nom', 'description', 'icon', 'color', 'critere', 'actif', 'translations',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'translations' => 'array',
        ];
    }

    public function badges(): HasMany
    {
        return $this->hasMany(Badge::class, 'nom', 'nom');
    }
}
