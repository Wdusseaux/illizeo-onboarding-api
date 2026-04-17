<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('is_addon')->default(false)->after('populaire');
            $table->string('addon_type')->nullable()->after('is_addon'); // cooptation, ai
            $table->integer('ai_ocr_scans')->nullable()->after('addon_type');
            $table->integer('ai_bot_messages')->nullable()->after('ai_ocr_scans');
            $table->integer('ai_contrat_generations')->nullable()->after('ai_bot_messages');
            $table->string('ai_model')->nullable()->after('ai_contrat_generations');
            $table->decimal('ai_extra_scan_price_chf', 6, 2)->nullable()->after('ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['is_addon', 'addon_type', 'ai_ocr_scans', 'ai_bot_messages', 'ai_contrat_generations', 'ai_model', 'ai_extra_scan_price_chf']);
        });
    }
};
