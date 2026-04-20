<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_translations', function (Blueprint $table) {
            $table->id();
            $table->string('source_lang', 5);
            $table->string('target_lang', 5);
            $table->text('source_text');
            $table->text('translated_text');
            $table->string('hash', 64)->index(); // sha256 of source_lang+target_lang+source_text
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_translations');
    }
};
