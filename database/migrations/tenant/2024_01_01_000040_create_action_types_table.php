<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // document, formulaire, formation, etc.
            $table->string('label');
            $table->string('icone')->nullable();
            $table->string('couleur_bg')->default('#E3F2FD');
            $table->string('couleur_texte')->default('#1A73E8');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_types');
    }
};
