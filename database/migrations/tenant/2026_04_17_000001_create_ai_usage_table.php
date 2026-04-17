<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // ocr_scan, bot_message, contrat_generation
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('collaborateur_id')->nullable();
            $table->string('model')->nullable(); // claude-opus-4-6, etc.
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost_usd', 8, 6)->default(0); // actual API cost
            $table->json('metadata')->nullable(); // extra info
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
