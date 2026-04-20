<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = [
        'nom', 'description', 'obligatoire', 'type', 'is_template', 'categorie_id',
        'status', 'collaborateur_id', 'fichier_path',
        'fichier_modele_path', 'fichier_modele_original',
        'user_id', 'fichier_original', 'fichier_taille', 'fichier_mime',
        'validated_by', 'validated_at', 'refuse_motif', 'notes', 'translations',
    ];

    protected function casts(): array
    {
        return [
            'obligatoire' => 'boolean',
            'is_template' => 'boolean',
            'fichier_taille' => 'integer',
            'validated_at' => 'datetime',
            'translations' => 'array',
        ];
    }

    protected $appends = ['url'];

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(DocumentCategorie::class, 'categorie_id');
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    protected function url(): Attribute
    {
        return Attribute::get(function () {
            if (!$this->fichier_path || !Storage::disk('local')->exists($this->fichier_path)) {
                return null;
            }

            return url("/api/v1/documents/{$this->id}/download");
        });
    }
}
