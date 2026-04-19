<?php

namespace App\Traits;

use App\Models\AuditLog;

/**
 * Trait Auditable — auto-log create/update/delete on Eloquent models.
 *
 * Usage: `use Auditable;` in your model.
 * Optional: override `getAuditLabel()` to customize the entity label.
 * Optional: define `$auditExclude = ['updated_at', 'password']` to exclude fields.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->logAudit('created');
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            $excluded = $model->auditExclude ?? ['updated_at', 'created_at', 'remember_token'];
            $filteredChanges = array_diff_key($changes, array_flip($excluded));

            if (empty($filteredChanges)) return;

            $oldValues = [];
            foreach (array_keys($filteredChanges) as $key) {
                $oldValues[$key] = $model->getOriginal($key);
            }

            $model->logAudit('updated', $oldValues, $filteredChanges);
        });

        static::deleted(function ($model) {
            $model->logAudit('deleted');
        });
    }

    protected function logAudit(string $action, ?array $oldValues = null, ?array $newValues = null): void
    {
        try {
            $entityType = $this->getAuditEntityType();
            $entityLabel = $this->getAuditLabel();

            $descriptions = [
                'created' => "{$this->getAuditEntityName()} créé(e) : {$entityLabel}",
                'updated' => "{$this->getAuditEntityName()} modifié(e) : {$entityLabel}",
                'deleted' => "{$this->getAuditEntityName()} supprimé(e) : {$entityLabel}",
            ];

            AuditLog::log(
                action: "{$entityType}_{$action}",
                entityType: $entityType,
                entityId: $this->id,
                entityLabel: $entityLabel,
                description: $descriptions[$action] ?? "{$entityType} {$action}",
                oldValues: $oldValues,
                newValues: $newValues ?? ($action === 'created' ? $this->attributesToArray() : null),
            );
        } catch (\Exception $e) {
            // Never block the main operation due to audit failure
            \Log::warning("Audit log failed: " . $e->getMessage());
        }
    }

    protected function getAuditEntityType(): string
    {
        // Convert "App\Models\Collaborateur" → "collaborateur"
        return strtolower(class_basename(static::class));
    }

    protected function getAuditEntityName(): string
    {
        $names = [
            'collaborateur' => 'Collaborateur',
            'parcours' => 'Parcours',
            'action' => 'Action',
            'phase' => 'Phase',
            'groupe' => 'Groupe',
            'workflow' => 'Workflow',
            'emailtemplate' => 'Template email',
            'contrat' => 'Contrat',
            'user' => 'Utilisateur',
            'role' => 'Rôle',
            'integration' => 'Intégration',
            'equipment' => 'Matériel',
            'equipmenttype' => 'Type matériel',
            'signaturedocument' => 'Document signature',
            'npssurveyr' => 'Enquête NPS',
            'cooptation' => 'Cooptation',
            'cooptationcampaign' => 'Campagne cooptation',
            'companysetting' => 'Paramètre',
            'buddypair' => 'Binôme buddy',
        ];
        return $names[$this->getAuditEntityType()] ?? class_basename(static::class);
    }

    protected function getAuditLabel(): string
    {
        // Override in model for better labels
        if (method_exists($this, 'customAuditLabel')) {
            return $this->customAuditLabel();
        }
        return $this->nom ?? $this->name ?? $this->titre ?? $this->email ?? "#{$this->id}";
    }
}
