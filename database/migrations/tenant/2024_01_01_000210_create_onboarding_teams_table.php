<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reusable onboarding teams (e.g. "Team Genève")
        Schema::create('onboarding_teams', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('site')->nullable(); // Auto-assign for this site
            $table->string('departement')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // Members of an onboarding team (the accompagnants)
        Schema::create('onboarding_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('onboarding_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role'); // manager, hrbp, buddy, it, recruteur, other
            $table->unique(['team_id', 'user_id']);
        });

        // Assignment: which team/individuals accompany a collaborateur
        Schema::create('collaborateur_accompagnants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collaborateur_id')->constrained('collaborateurs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role'); // manager, hrbp, buddy, it, recruteur
            $table->foreignId('team_id')->nullable()->constrained('onboarding_teams')->nullOnDelete();
            $table->timestamps();
            $table->unique(['collaborateur_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborateur_accompagnants');
        Schema::dropIfExists('onboarding_team_members');
        Schema::dropIfExists('onboarding_teams');
    }
};
