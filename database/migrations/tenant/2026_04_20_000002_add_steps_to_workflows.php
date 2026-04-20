<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->json('steps')->nullable()->after('actif');
            $table->string('description')->nullable()->after('nom');
            $table->string('color', 7)->default('#E91E63')->after('actif');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn(['steps', 'description', 'color']);
        });
    }
};
