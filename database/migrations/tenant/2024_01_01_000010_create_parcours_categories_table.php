<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcours_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // onboarding, offboarding, crossboarding, reboarding
            $table->string('nom');
            $table->string('description')->nullable();
            $table->string('couleur')->default('#C2185B');
            $table->string('icone')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcours_categories');
    }
};
