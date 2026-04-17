<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentaireTache extends Model
{
    use HasFactory;

    protected $table = 'commentaires_taches';

    protected $fillable = [
        'tache_id',
        'user_id',
        'contenu',
    ];

    // ─── Relations ───

    public function tache(): BelongsTo
    {
        return $this->belongsTo(Tache::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
