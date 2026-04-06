<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // welcome, action_assigned, reminder, doc_validated, doc_refused, action_completed, doc_submitted, parcours_completed, new_collaborateur, message
            $table->string('title');
            $table->text('content');
            $table->string('icon')->default('bell'); // bell, check, alert, file, zap, trophy, mail, user
            $table->string('color')->default('#C2185B');
            $table->json('data')->nullable(); // { action_id, collaborateur_id, parcours_id, etc. }
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
