<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-devise pour les Plans : prix natifs + Stripe Price IDs en USD/GBP/CAD/AUD/JPY.
     * Permet de facturer les clients dans leur devise locale (Stripe convertit ~1% sur payout CHF).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Prix natifs (calculés depuis CHF via exchangerate-api au moment du seed)
            $table->decimal('prix_usd_mensuel', 10, 2)->nullable()->after('prix_chf_mensuel');
            $table->decimal('prix_gbp_mensuel', 10, 2)->nullable()->after('prix_usd_mensuel');
            $table->decimal('prix_cad_mensuel', 10, 2)->nullable()->after('prix_gbp_mensuel');
            $table->decimal('prix_aud_mensuel', 10, 2)->nullable()->after('prix_cad_mensuel');
            $table->decimal('prix_jpy_mensuel', 10, 2)->nullable()->after('prix_aud_mensuel');

            // Stripe Price IDs
            $table->string('stripe_price_id_usd')->nullable()->after('stripe_price_id_chf');
            $table->string('stripe_price_id_gbp')->nullable()->after('stripe_price_id_usd');
            $table->string('stripe_price_id_cad')->nullable()->after('stripe_price_id_gbp');
            $table->string('stripe_price_id_aud')->nullable()->after('stripe_price_id_cad');
            $table->string('stripe_price_id_jpy')->nullable()->after('stripe_price_id_aud');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'prix_usd_mensuel', 'prix_gbp_mensuel', 'prix_cad_mensuel', 'prix_aud_mensuel', 'prix_jpy_mensuel',
                'stripe_price_id_usd', 'stripe_price_id_gbp', 'stripe_price_id_cad', 'stripe_price_id_aud', 'stripe_price_id_jpy',
            ]);
        });
    }
};
