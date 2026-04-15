<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            if (!Schema::hasColumn('contrats', 'fichier_path')) {
                $table->string('fichier_path')->nullable()->after('fichier');
            }
            if (!Schema::hasColumn('contrats', 'translations')) {
                $table->json('translations')->nullable()->after('fichier_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            if (Schema::hasColumn('contrats', 'fichier_path')) $table->dropColumn('fichier_path');
        });
    }
};
