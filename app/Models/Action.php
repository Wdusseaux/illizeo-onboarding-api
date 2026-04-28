<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class Action extends Model
{
    use Auditable;

    protected function customAuditLabel(): string { return $this->titre ?? "#{$this->id}"; }
    protected $fillable = [
        'titre', 'action_type_id', 'phase_id', 'parcours_id',
        'delai_relatif', 'obligatoire', 'description', 'lien_externe',
        'duree_estimee', 'xp', 'heure_default', 'accompagnant_role',
        'pieces_requises', 'assignation_mode', 'assignation_valeurs',
        'options', 'translations',
    ];

    protected function casts(): array
    {
        return [
            'obligatoire' => 'boolean',
            'pieces_requises' => 'array',
            'assignation_valeurs' => 'array',
            'options' => 'array',
            'translations' => 'array',
        ];
    }

    public function actionType(): BelongsTo
    {
        return $this->belongsTo(ActionType::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }
}
