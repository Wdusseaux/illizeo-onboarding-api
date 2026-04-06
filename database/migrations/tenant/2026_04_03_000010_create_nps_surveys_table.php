<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_surveys', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->text('description')->nullable();
            $table->string('type')->default('nps');
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->nullOnDelete();
            $table->string('declencheur')->default('fin_parcours');
            $table->date('date_envoi')->nullable();
            $table->json('questions');
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_surveys');
    }
};
