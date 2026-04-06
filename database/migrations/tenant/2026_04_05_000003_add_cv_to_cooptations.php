<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooptations', function (Blueprint $table) {
            $table->string('cv_path')->nullable()->after('notes');
            $table->string('cv_original_name')->nullable()->after('cv_path');
            $table->string('linkedin_url')->nullable()->after('cv_original_name');
            $table->string('telephone')->nullable()->after('linkedin_url');
        });
    }

    public function down(): void
    {
        Schema::table('cooptations', function (Blueprint $table) {
            $table->dropColumn(['cv_path', 'cv_original_name', 'linkedin_url', 'telephone']);
        });
    }
};
