<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collaborateur_id')->nullable()->constrained('collaborateurs')->nullOnDelete();
            $table->string('nom');
            $table->string('description')->nullable();
            $table->string('icon')->default('trophy');
            $table->string('color')->default('#F9A825');
            $table->timestamp('earned_at')->useCurrent();
            $table->foreignId('workflow_id')->nullable()->constrained('workflows')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};
