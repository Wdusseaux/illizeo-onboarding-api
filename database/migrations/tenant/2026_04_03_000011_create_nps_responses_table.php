<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('nps_surveys')->cascadeOnDelete();
            $table->foreignId('collaborateur_id')->constrained('collaborateurs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('score')->nullable();
            $table->decimal('rating', 3, 1)->nullable();
            $table->json('answers')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('token')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_responses');
    }
};
