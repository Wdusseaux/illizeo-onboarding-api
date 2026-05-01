<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field-level role permissions on collaborateur_field_config.
     * Both columns are JSON arrays of role slugs (super_admin, admin_rh, manager, hrbp,
     * collaborateur, auditeur, ...). NULL = visible/editable by everyone (legacy default).
     */
    public function up(): void
    {
        Schema::table('collaborateur_field_config', function (Blueprint $table) {
            $table->json('visible_roles')->nullable()->after('list_values');
            $table->json('editable_roles')->nullable()->after('visible_roles');
        });
    }

    public function down(): void
    {
        Schema::table('collaborateur_field_config', function (Blueprint $table) {
            $table->dropColumn(['visible_roles', 'editable_roles']);
        });
    }
};
