<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrats', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('type'); // CDI, CDD, Stage, Alternance, Avenant
            $table->string('juridiction'); // Suisse, France, Multi
            $table->integer('variables')->default(0);
            $table->date('derniere_maj')->nullable();
            $table->boolean('actif')->default(true);
            $table->string('fichier')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrats');
    }
};
