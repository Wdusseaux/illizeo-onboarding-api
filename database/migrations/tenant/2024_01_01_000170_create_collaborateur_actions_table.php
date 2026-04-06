<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaborateur_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collaborateur_id')->constrained('collaborateurs')->cascadeOnDelete();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->enum('status', ['a_faire', 'en_cours', 'termine', 'annule'])->default('a_faire');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('note')->nullable();
            $table->json('response_data')->nullable(); // For questionnaire answers, form data, etc.
            $table->timestamps();
            $table->unique(['collaborateur_id', 'action_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborateur_actions');
    }
};
