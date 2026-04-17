<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SousProjet extends Model
{
    use HasFactory;

    protected $table = 'sous_projets';

    protected $fillable = [
        'projet_id',
        'nom',
        'heures',
        'est_facturable',
    ];

    protected function casts(): array
    {
        return [
            'heures' => 'decimal:2',
            'est_facturable' => 'boolean',
        ];
    }

    // ─── Relations ───

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class);
    }

    public function taches(): HasMany
    {
        return $this->hasMany(Tache::class);
    }
}
