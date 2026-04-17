<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();
            $table->foreignId('sous_projet_id')->nullable()->constrained('sous_projets')->nullOnDelete();

            $table->string('titre');
            $table->enum('statut', ['todo', 'in_progress', 'done', 'cancelled'])->default('todo');
            $table->enum('priorite', ['urgent', 'high', 'normal', 'low'])->default('normal');

            // Lead = assigné principal (un seul, peut être null pour "non assigné")
            // Les collaborateurs additionnels sont dans la table pivot taches_membres
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('due_date')->nullable();
            $table->json('tags')->nullable(); // Array de strings

            $table->timestamps();

            $table->index(['projet_id', 'statut']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taches');
    }
};
