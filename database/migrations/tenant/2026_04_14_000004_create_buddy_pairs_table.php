<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newcomer_id')->constrained('collaborateurs')->cascadeOnDelete();
            $table->foreignId('buddy_id')->constrained('collaborateurs')->cascadeOnDelete();
            $table->string('status')->default('active'); // active, completed, cancelled
            $table->json('checklist')->nullable(); // array of booleans for 8 checklist items
            $table->json('notes')->nullable(); // array of {text, date} objects
            $table->decimal('rating', 3, 1)->nullable(); // 1.0 to 5.0
            $table->text('feedback_comment')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_pairs');
    }
};
