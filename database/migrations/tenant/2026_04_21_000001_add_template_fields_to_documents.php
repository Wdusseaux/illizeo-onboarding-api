<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('type');
            $table->string('fichier_modele_path')->nullable()->after('fichier_path');
            $table->string('fichier_modele_original')->nullable()->after('fichier_modele_path');
            $table->text('description')->nullable()->after('nom');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['is_template', 'fichier_modele_path', 'fichier_modele_original', 'description']);
        });
    }
};
