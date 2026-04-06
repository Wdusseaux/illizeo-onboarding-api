<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupes', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('couleur', 10)->default('#C2185B');
            // Critère auto-groupe
            $table->string('critere_type')->nullable(); // site, departement, contrat
            $table->string('critere_valeur')->nullable();
            $table->timestamps();
        });

        // Table pivot groupe <-> collaborateur
        Schema::create('collaborateur_groupe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collaborateur_id')->constrained('collaborateurs')->cascadeOnDelete();
            $table->foreignId('groupe_id')->constrained('groupes')->cascadeOnDelete();
            $table->unique(['collaborateur_id', 'groupe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborateur_groupe');
        Schema::dropIfExists('groupes');
    }
};
