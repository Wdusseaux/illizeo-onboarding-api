<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            // XP awarded to the collaborateur when this action is completed.
            // Used both for the dashboard pill ("+50 XP") and the leaderboard sum.
            $table->unsignedInteger('xp')->default(50)->after('duree_estimee');
            // Default time slot for the calendar view (HH:MM, e.g. "09:00").
            // Without it the calendar falls back to a derived slot, which causes
            // collisions when two actions share the same day.
            $table->string('heure_default', 5)->nullable()->after('xp');
            // Which accompagnant role is responsible for this action — drives the
            // "Avec X · Manager" subtitle on the focus card. Values match
            // CollaborateurAccompagnant.role: buddy | manager | hrbp | it | admin_rh
            $table->string('accompagnant_role', 32)->nullable()->after('heure_default');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropColumn(['xp', 'heure_default', 'accompagnant_role']);
        });
    }
};
