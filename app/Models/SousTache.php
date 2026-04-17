<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SousTache extends Model
{
    use HasFactory;

    protected $table = 'sous_taches';

    protected $fillable = [
        'tache_id',
        'titre',
        'est_terminee',
    ];

    protected function casts(): array
    {
        return [
            'est_terminee' => 'boolean',
        ];
    }

    // ─── Relations ───

    public function tache(): BelongsTo
    {
        return $this->belongsTo(Tache::class);
    }
}
