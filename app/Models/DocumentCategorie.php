<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentCategorie extends Model
{
    protected $table = 'document_categories';

    protected $fillable = ['slug', 'titre'];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'categorie_id');
    }
}
