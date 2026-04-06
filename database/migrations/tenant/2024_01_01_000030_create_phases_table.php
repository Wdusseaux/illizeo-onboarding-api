<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('delai_debut')->nullable(); // J-30, J+0, etc.
            $table->string('delai_fin')->nullable();
            $table->string('couleur')->default('#4CAF50');
            $table->string('icone')->nullable();
            $table->integer('actions_defaut')->default(0);
            $table->integer('ordre')->default(0);
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phases');
    }
};
