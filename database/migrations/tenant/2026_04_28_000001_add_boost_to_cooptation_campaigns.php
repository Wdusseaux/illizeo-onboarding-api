<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooptation_campaigns', function (Blueprint $table) {
            $table->boolean('boost_active')->default(false);
            $table->decimal('boost_multiplier', 4, 2)->default(1.00);
            $table->string('boost_label')->nullable();
            $table->date('boost_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cooptation_campaigns', function (Blueprint $table) {
            $table->dropColumn(['boost_active', 'boost_multiplier', 'boost_label', 'boost_until']);
        });
    }
};
