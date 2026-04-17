<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneCout extends Model
{
    use HasFactory;

    protected $table = 'lignes_couts';

    protected $fillable = [
        'projet_id',
        'libelle',
        'montant',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
        ];
    }

    // ─── Relations ───

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class);
    }
}
