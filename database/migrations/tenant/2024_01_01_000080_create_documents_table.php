<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('titre');
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->boolean('obligatoire')->default(false);
            $table->enum('type', ['upload', 'formulaire'])->default('upload');
            $table->foreignId('categorie_id')->constrained('document_categories')->cascadeOnDelete();
            $table->enum('status', ['manquant', 'soumis', 'en_attente', 'valide', 'refuse'])->default('manquant');
            $table->foreignId('collaborateur_id')->nullable()->constrained('collaborateurs')->cascadeOnDelete();
            $table->string('fichier_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
