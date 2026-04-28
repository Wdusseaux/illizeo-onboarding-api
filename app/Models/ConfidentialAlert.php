<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfidentialAlert extends Model
{
    protected $fillable = ['user_id', 'anonymous', 'category', 'content', 'status'];

    protected function casts(): array
    {
        return ['anonymous' => 'boolean'];
    }
}
