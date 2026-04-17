<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_couts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();
            $table->string('libelle');
            $table->decimal('montant', 12, 2)->default(0);
            $table->timestamps();

            $table->index('projet_id');
        });

        Schema::create('taux_horaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();
            $table->string('role_libelle');
            $table->decimal('taux', 10, 2)->default(0);
            $table->timestamps();

            $table->index('projet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taux_horaires');
        Schema::dropIfExists('lignes_couts');
    }
};
