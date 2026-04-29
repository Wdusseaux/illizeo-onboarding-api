<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AI scoring + CV parsing fields. Computed asynchronously by
// CooptationScoringService and CvParsingService — see those classes
// for the prompt structure and cost tracking.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooptations', function (Blueprint $table) {
            // 0..100 score, higher = more likely to convert / more urgent.
            $table->decimal('priority_score', 5, 2)->nullable();
            // Short narrative reason from the LLM ("Profil B2B SaaS aligné, …").
            $table->text('priority_reason')->nullable();
            // Suggested next action ("Programmer entretien", "Faire une offre"…).
            $table->string('priority_action', 60)->nullable();
            // Last successful scoring run.
            $table->timestamp('priority_computed_at')->nullable();
            // Model identifier so we can re-score on model upgrades.
            $table->string('priority_model_version', 80)->nullable();
            // Structured data extracted from the CV (skills, years_xp, last_role, education, languages…).
            $table->json('cv_parsed_data')->nullable();
            $table->timestamp('cv_parsed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cooptations', function (Blueprint $table) {
            $table->dropColumn(['priority_score', 'priority_reason', 'priority_action', 'priority_computed_at', 'priority_model_version', 'cv_parsed_data', 'cv_parsed_at']);
        });
    }
};
