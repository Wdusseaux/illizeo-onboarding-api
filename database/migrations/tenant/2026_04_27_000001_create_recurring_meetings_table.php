<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->text('description')->nullable();
            // Frequency: weekly, biweekly, monthly, milestone (J+7, J+30 etc)
            $table->string('frequence');
            // For weekly/biweekly: ISO day-of-week (1=Monday..7=Sunday). Null for milestone.
            $table->unsignedTinyInteger('jour_semaine')->nullable();
            // For milestone freq: list of J+X markers as JSON array of ints, e.g. [7,30,60,90]
            $table->json('milestones')->nullable();
            // Time of day "HH:mm"
            $table->string('heure', 5)->default('09:00');
            // Duration in minutes
            $table->unsignedSmallInteger('duree_min')->default(30);
            // Lieu / lien visio (free text or URL)
            $table->string('lieu')->nullable();
            // Participant roles: array among ['manager','buddy','rh','dg','it']
            $table->json('participants_roles')->nullable();
            // Optional: scope to a specific parcours, null = applies to all
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->nullOnDelete();
            // Auto-sync to connected calendar provider
            $table->boolean('auto_sync_calendar')->default(false);
            $table->boolean('actif')->default(true);
            $table->json('translations')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_meeting_id')->constrained('recurring_meetings')->cascadeOnDelete();
            $table->foreignId('collaborateur_id')->constrained('collaborateurs')->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duree_min')->default(30);
            // External calendar event tracking
            $table->string('external_provider')->nullable(); // 'microsoft' | 'google'
            $table->string('external_event_id')->nullable();
            $table->string('external_join_url')->nullable();
            $table->timestamp('synced_at')->nullable();
            // Status: scheduled, completed, cancelled
            $table->string('status')->default('scheduled');
            $table->timestamps();

            $table->index(['collaborateur_id', 'scheduled_at']);
            $table->unique(['recurring_meeting_id', 'collaborateur_id', 'scheduled_at'], 'mi_rm_collab_at_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_instances');
        Schema::dropIfExists('recurring_meetings');
    }
};
