<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignatureLog extends Model
{
    protected $fillable = [
        'collaborateur_id', 'user_id', 'provider', 'envelope_id',
        'document_name', 'status', 'sent_at', 'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function collaborateur()
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
