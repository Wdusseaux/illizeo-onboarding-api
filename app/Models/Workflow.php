<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Workflow extends Model
{
    use Auditable;
    protected $fillable = [
        'nom', 'declencheur', 'action', 'destinataire', 'actif',
        'target_user_id', 'target_group_id', 'badge_name', 'badge_icon', 'badge_color',
        'email_subject', 'email_body', 'bot_message', 'translations',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'translations' => 'array',
        ];
    }
}
