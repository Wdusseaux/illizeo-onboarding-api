<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'scope_groups')) {
                $table->json('scope_groups')->nullable()->after('scope_values');
            }
            if (!Schema::hasColumn('roles', 'exclude_self')) {
                $table->boolean('exclude_self')->default(false)->after('scope_groups');
            }
            if (!Schema::hasColumn('roles', 'exclusion_groups')) {
                $table->json('exclusion_groups')->nullable()->after('exclude_self');
            }
            if (!Schema::hasColumn('roles', 'security_2fa')) {
                $table->string('security_2fa')->default('optional')->after('exclusion_groups');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['scope_groups', 'exclude_self', 'exclusion_groups', 'security_2fa']);
        });
    }
};
