<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcours', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->foreignId('categorie_id')->constrained('parcours_categories')->cascadeOnDelete();
            $table->integer('actions_count')->default(0);
            $table->integer('docs_count')->default(0);
            $table->integer('collaborateurs_actifs')->default(0);
            $table->enum('status', ['actif', 'brouillon', 'archive'])->default('brouillon');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcours');
    }
};
