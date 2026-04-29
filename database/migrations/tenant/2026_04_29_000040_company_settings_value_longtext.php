<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promote company_settings.value from TEXT (~64KB) to LONGTEXT (~4GB) so we can
 * store base64-encoded user avatars (`avatar_<userId>`) and other large blobs
 * without silent MySQL truncation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_settings')) return;
        Schema::table('company_settings', function (Blueprint $table) {
            $table->longText('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('company_settings')) return;
        Schema::table('company_settings', function (Blueprint $table) {
            $table->text('value')->nullable()->change();
        });
    }
};
