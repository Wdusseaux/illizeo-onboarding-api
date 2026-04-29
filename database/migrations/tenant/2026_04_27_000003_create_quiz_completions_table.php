<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quiz_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('collaborateur_id')->nullable();
            // The company_blocks row that defined the quiz (nullable so fallback quiz still records)
            $table->unsignedBigInteger('block_id')->nullable();
            $table->unsignedInteger('correct')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('xp_earned')->default(0);
            $table->json('answers')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'block_id']);
            $table->index('collaborateur_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_completions');
    }
};
