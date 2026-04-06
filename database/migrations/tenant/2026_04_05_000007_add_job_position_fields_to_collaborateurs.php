<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborateurs', function (Blueprint $table) {
            // Job Information
            $table->string('job_title')->nullable()->after('poste');
            $table->string('job_family')->nullable()->after('job_title');
            $table->string('job_code')->nullable()->after('job_family');
            $table->string('job_level')->nullable()->after('job_code');
            $table->string('employment_type')->nullable()->after('job_level'); // CDI, CDD, Stage, Alternance, Freelance
            $table->date('date_fin_contrat')->nullable()->after('employment_type');
            $table->string('motif_embauche')->nullable()->after('date_fin_contrat'); // Création de poste, Remplacement, etc.

            // Position Information
            $table->string('position_title')->nullable()->after('motif_embauche');
            $table->string('position_code')->nullable()->after('position_title');
            $table->string('business_unit')->nullable()->after('position_code');
            $table->string('division')->nullable()->after('business_unit');
            $table->string('cost_center')->nullable()->after('division');
            $table->string('location_code')->nullable()->after('cost_center');
            $table->string('manager_id')->nullable()->after('location_code');
            $table->string('dotted_line_manager')->nullable()->after('manager_id');
            $table->string('work_schedule')->nullable()->after('dotted_line_manager'); // Temps plein, Temps partiel, etc.
            $table->string('fte')->nullable()->after('work_schedule'); // Full Time Equivalent (0.0 - 1.0)
        });
    }

    public function down(): void
    {
        Schema::table('collaborateurs', function (Blueprint $table) {
            $table->dropColumn([
                'job_title', 'job_family', 'job_code', 'job_level',
                'employment_type', 'date_fin_contrat', 'motif_embauche',
                'position_title', 'position_code', 'business_unit', 'division',
                'cost_center', 'location_code', 'manager_id', 'dotted_line_manager',
                'work_schedule', 'fte',
            ]);
        });
    }
};
