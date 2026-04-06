<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationConfig extends Model
{
    protected $table = 'notifications_config';

    protected $fillable = ['nom', 'canal', 'actif', 'categorie'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }
}
