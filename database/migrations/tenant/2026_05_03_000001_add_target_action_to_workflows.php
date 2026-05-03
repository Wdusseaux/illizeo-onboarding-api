<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            // Used by the "Assigner action automatiquement" workflow action to know
            // which Action template to instantiate as a CollaborateurAction.
            $table->unsignedBigInteger('target_action_id')->nullable()->after('target_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('target_action_id');
        });
    }
};
