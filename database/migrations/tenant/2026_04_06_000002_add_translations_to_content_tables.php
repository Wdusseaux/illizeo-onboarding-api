<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'parcours',
        'phases',
        'actions',
        'groupes',
        'workflows',
        'email_templates',
        'contrats',
        'onboarding_teams',
        'documents',
        'nps_surveys',
        'badge_templates',
        'equipments',
        'equipment_packages',
        'signature_documents',
        'company_blocks',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'translations')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->json('translations')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'translations')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('translations');
                });
            }
        }
    }
};
