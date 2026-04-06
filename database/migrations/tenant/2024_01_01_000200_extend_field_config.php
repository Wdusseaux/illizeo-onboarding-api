<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborateur_field_config', function (Blueprint $table) {
            $table->string('field_type')->default('text')->after('section'); // text, number, date, list, boolean
            $table->json('list_values')->nullable()->after('field_type'); // For list type: ["CDI","CDD","Stage"]
            $table->string('label_en')->nullable()->after('label'); // English label
        });
    }

    public function down(): void
    {
        Schema::table('collaborateur_field_config', function (Blueprint $table) {
            $table->dropColumn(['field_type', 'list_values', 'label_en']);
        });
    }
};
