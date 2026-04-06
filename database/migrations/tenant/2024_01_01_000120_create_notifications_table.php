<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_config', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->enum('canal', ['email', 'in_app', 'slack'])->default('email');
            $table->boolean('actif')->default(true);
            $table->string('categorie')->default('general'); // general, ressource
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_config');
    }
};
