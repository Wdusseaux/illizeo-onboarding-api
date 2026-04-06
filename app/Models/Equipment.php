<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Equipment extends Model
{
    protected $table = 'equipments';

    protected $fillable = [
        'equipment_type_id', 'nom', 'numero_serie', 'marque', 'modele',
        'etat', 'collaborateur_id', 'assigned_by', 'assigned_at', 'returned_at',
        'date_achat', 'valeur', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'returned_at' => 'datetime',
            'date_achat' => 'date',
            'valeur' => 'decimal:2',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(Collaborateur::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
