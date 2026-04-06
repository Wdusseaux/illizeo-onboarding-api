<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborateurs', function (Blueprint $table) {
            $table->enum('dossier_status', ['incomplet', 'complet', 'valide', 'exporte'])->default('incomplet')->after('status');
            $table->timestamp('dossier_validated_at')->nullable()->after('dossier_status');
            $table->foreignId('dossier_validated_by')->nullable()->constrained('users')->nullOnDelete()->after('dossier_validated_at');
            $table->timestamp('dossier_exported_at')->nullable()->after('dossier_validated_by');
            $table->string('dossier_export_target')->nullable()->after('dossier_exported_at'); // successfactors, personio, bamboohr, lucca, manual
        });
    }

    public function down(): void
    {
        Schema::table('collaborateurs', function (Blueprint $table) {
            $table->dropColumn(['dossier_status', 'dossier_validated_at', 'dossier_validated_by', 'dossier_exported_at', 'dossier_export_target']);
        });
    }
};
