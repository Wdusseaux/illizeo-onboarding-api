<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Weekly / on-demand pulse: 1-5 mood with optional comment.
        Schema::create('mood_checkins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('collaborateur_id')->nullable();
            $table->unsignedTinyInteger('mood'); // 1=very_bad, 2=bad, 3=neutral, 4=good, 5=very_good
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('collaborateur_id');
        });

        // Suggestions box / bug reports / product feedback (free-form, optionally anonymous).
        Schema::create('feedback_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable(); // null = anonymous
            $table->unsignedBigInteger('collaborateur_id')->nullable();
            $table->string('category')->default('suggestion'); // suggestion | bug | improvement | other
            $table->text('content');
            $table->boolean('anonymous')->default(false);
            $table->string('status')->default('open'); // open | reviewing | done | dismissed
            $table->timestamps();

            $table->index('status');
            $table->index('category');
        });

        // Ratings of buddy / manager (peer feedback, ascending).
        Schema::create('buddy_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // rater
            $table->unsignedBigInteger('collaborateur_id')->nullable();
            $table->string('target_type'); // 'buddy' | 'manager'
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->unsignedTinyInteger('rating'); // 1-5
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'target_type']);
            $table->index('target_user_id');
        });

        // Confidential RH alerts (RPS, harassment, other). Restricted-access read.
        Schema::create('confidential_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable(); // null when truly anonymous
            $table->boolean('anonymous')->default(false);
            $table->string('category'); // rps | harcelement | discrimination | autre
            $table->text('content');
            $table->string('status')->default('new'); // new | acknowledged | in_progress | closed
            $table->timestamps();

            $table->index('status');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confidential_alerts');
        Schema::dropIfExists('buddy_ratings');
        Schema::dropIfExists('feedback_suggestions');
        Schema::dropIfExists('mood_checkins');
    }
};
