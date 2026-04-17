<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sous_taches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tache_id')->constrained('taches')->cascadeOnDelete();
            $table->string('titre');
            $table->boolean('est_terminee')->default(false);
            $table->timestamps();

            $table->index('tache_id');
        });

        Schema::create('commentaires_taches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tache_id')->constrained('taches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('contenu');
            $table->timestamps();

            $table->index('tache_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commentaires_taches');
        Schema::dropIfExists('sous_taches');
    }
};
