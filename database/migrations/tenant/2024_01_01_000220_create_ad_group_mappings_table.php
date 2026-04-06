<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('ad_group_id'); // Azure AD group ID
            $table->string('ad_group_name'); // Display name
            $table->string('illizeo_role'); // super_admin, admin, admin_rh, manager, onboardee
            $table->boolean('auto_provision')->default(true); // Auto-create user on sync
            $table->boolean('auto_deprovision')->default(false); // Disable user when removed from group
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique('ad_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_group_mappings');
    }
};
