<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcours_phase', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcours_id')->constrained('parcours')->cascadeOnDelete();
            $table->foreignId('phase_id')->constrained('phases')->cascadeOnDelete();
            $table->integer('ordre')->default(0);
            $table->unique(['parcours_id', 'phase_id']);
        });

        // Remove the old single FK from phases (keep column for backward compat but make it nullable)
        // The relation is now managed via the pivot table
    }

    public function down(): void
    {
        Schema::dropIfExists('parcours_phase');
    }
};
