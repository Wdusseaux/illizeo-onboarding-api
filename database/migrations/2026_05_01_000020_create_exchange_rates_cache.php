<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache des taux de change CHF → autres devises.
     * Une ligne par devise cible. Refresh quotidien via le cron.
     */
    public function up(): void
    {
        Schema::create('exchange_rates_cache', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3)->default('CHF');
            $table->string('target_currency', 3);
            $table->decimal('rate', 12, 6); // 1 CHF = X target_currency
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['base_currency', 'target_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates_cache');
    }
};
