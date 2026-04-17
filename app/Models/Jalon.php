<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Jalon extends Model
{
    use HasFactory;

    protected $table = 'jalons';

    protected $fillable = [
        'projet_id',
        'libelle',
        'montant',
        'date',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date' => 'date',
        ];
    }

    // ─── Relations ───

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class);
    }
}
