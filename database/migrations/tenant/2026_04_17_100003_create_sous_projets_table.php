<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sous_projets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();
            $table->string('nom');
            // Heures consommées : alimentées par le module Présences
            // (ce module Projets ne fait QUE de la lecture sur ce champ)
            $table->decimal('heures', 10, 2)->default(0);
            $table->boolean('est_facturable')->default(false);
            $table->timestamps();

            $table->index('projet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sous_projets');
    }
};
