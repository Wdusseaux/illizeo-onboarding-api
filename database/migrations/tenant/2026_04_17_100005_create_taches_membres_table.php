<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Collaborateurs additionnels d'une tâche (le lead est sur taches.lead_id)
        Schema::create('taches_membres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tache_id')->constrained('taches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tache_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taches_membres');
    }
};
