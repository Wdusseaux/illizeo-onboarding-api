<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // hero, text, mission, stats, video, values, team, cta
            $table->string('titre')->nullable();
            $table->text('contenu')->nullable();
            $table->json('data')->nullable(); // type-specific data
            $table->integer('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_blocks');
    }
};
