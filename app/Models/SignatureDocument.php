<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class SignatureDocument extends Model
{
    use Auditable;
    protected $fillable = [
        'titre', 'description', 'type', 'fichier_path', 'fichier_nom',
        'obligatoire', 'actif', 'translations', 'version',
    ];

    protected function casts(): array
    {
        return ['obligatoire' => 'boolean', 'actif' => 'boolean', 'translations' => 'array', 'version' => 'integer'];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SignatureLog::class);
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgement::class);
    }
}
