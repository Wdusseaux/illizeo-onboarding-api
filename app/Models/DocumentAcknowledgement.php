<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAcknowledgement extends Model
{
    protected $fillable = [
        'signature_document_id', 'collaborateur_id', 'user_id',
        'statut', 'signed_at', 'ip_address', 'commentaire',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SignatureDocument::class, 'signature_document_id');
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
