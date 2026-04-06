<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignatureDocument extends Model
{
    protected $fillable = [
        'titre', 'description', 'type', 'fichier_path', 'fichier_nom',
        'obligatoire', 'actif', 'translations',
    ];

    protected function casts(): array
    {
        return ['obligatoire' => 'boolean', 'actif' => 'boolean', 'translations' => 'array'];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SignatureLog::class);
    }
}
