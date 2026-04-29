<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // Separate exclusion list for the "Population autorisée" tab. The existing
            // `exclusion_groups` column keeps targeting the "Population cible" tab so
            // existing data is preserved. Each tab now owns its own exclusions.
            if (!Schema::hasColumn('roles', 'members_exclusion_groups')) {
                $table->json('members_exclusion_groups')->nullable()->after('exclusion_groups');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'members_exclusion_groups')) {
                $table->dropColumn('members_exclusion_groups');
            }
        });
    }
};
