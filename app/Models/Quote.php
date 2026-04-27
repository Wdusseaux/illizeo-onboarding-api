<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    protected $fillable = ['text', 'author', 'source', 'actif', 'translations'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'translations' => 'array',
        ];
    }
}
