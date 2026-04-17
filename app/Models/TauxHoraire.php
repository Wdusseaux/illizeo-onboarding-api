<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TauxHoraire extends Model
{
    use HasFactory;

    protected $table = 'taux_horaires';

    protected $fillable = [
        'projet_id',
        'role_libelle',
        'taux',
    ];

    protected function casts(): array
    {
        return [
            'taux' => 'decimal:2',
        ];
    }

    // ─── Relations ───

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class);
    }
}
