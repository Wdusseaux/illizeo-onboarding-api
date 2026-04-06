<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->foreignId('action_type_id')->constrained('action_types')->cascadeOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('phases')->nullOnDelete();
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->nullOnDelete();
            $table->string('delai_relatif')->nullable(); // J-30, J+0, J+7...
            $table->boolean('obligatoire')->default(false);
            $table->text('description')->nullable();
            $table->string('lien_externe')->nullable();
            $table->string('duree_estimee')->nullable();
            $table->json('pieces_requises')->nullable(); // ["Pièce d'identité", "RIB"]
            // Assignation
            $table->enum('assignation_mode', ['tous', 'individuel', 'groupe', 'site', 'departement', 'contrat', 'parcours', 'phase'])->default('tous');
            $table->json('assignation_valeurs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
