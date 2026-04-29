<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nps_surveys', function (Blueprint $table) {
            // For declencheur=delai_relatif: number of days after the collab's date_debut
            // when the survey should surface (e.g. 30 = "1 month after arrival").
            $table->unsignedInteger('delai_jours')->nullable()->after('declencheur');
            // For declencheur=fin_phase: id of the phase whose completion triggers the survey.
            $table->unsignedBigInteger('phase_id')->nullable()->after('delai_jours');
            $table->index('phase_id');
        });
    }

    public function down(): void
    {
        Schema::table('nps_surveys', function (Blueprint $table) {
            $table->dropIndex(['phase_id']);
            $table->dropColumn(['delai_jours', 'phase_id']);
        });
    }
};
