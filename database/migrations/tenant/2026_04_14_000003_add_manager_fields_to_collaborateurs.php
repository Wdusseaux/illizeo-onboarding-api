<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborateurs', function (Blueprint $table) {
            // Drop old string manager_id if it exists (was a plain text field)
            if (Schema::hasColumn('collaborateurs', 'manager_id')) {
                $table->dropColumn('manager_id');
            }
        });

        Schema::table('collaborateurs', function (Blueprint $table) {
            if (!Schema::hasColumn('collaborateurs', 'manager_id')) {
                $table->foreignId('manager_id')->nullable()->constrained('collaborateurs')->nullOnDelete()->after('parcours_id');
            }
            if (!Schema::hasColumn('collaborateurs', 'hr_manager_id')) {
                $table->foreignId('hr_manager_id')->nullable()->constrained('collaborateurs')->nullOnDelete()->after('manager_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('collaborateurs', function (Blueprint $table) {
            if (Schema::hasColumn('collaborateurs', 'hr_manager_id')) {
                $table->dropConstrainedForeignId('hr_manager_id');
            }
            if (Schema::hasColumn('collaborateurs', 'manager_id')) {
                $table->dropConstrainedForeignId('manager_id');
            }
        });

        // Restore original string column
        Schema::table('collaborateurs', function (Blueprint $table) {
            if (!Schema::hasColumn('collaborateurs', 'manager_id')) {
                $table->string('manager_id')->nullable()->after('location_code');
            }
        });
    }
};
