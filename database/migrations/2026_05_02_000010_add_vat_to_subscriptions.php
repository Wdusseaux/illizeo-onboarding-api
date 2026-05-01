<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Champs TVA pour la conformité fiscale CH/EU sur les souscriptions.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('currency');                       // ISO-2 du client (CH, FR, DE, ...)
            $table->string('customer_type', 20)->nullable()->after('country');                 // company | individual | freelance
            $table->string('vat_number', 32)->nullable()->after('customer_type');              // n° TVA EU pour reverse charge
            $table->string('vat_validation_status', 20)->nullable()->after('vat_number');     // valid | invalid | unverified
            $table->timestamp('vat_validated_at')->nullable()->after('vat_validation_status');
            $table->decimal('vat_rate', 5, 2)->default(0)->after('vat_validated_at');         // % appliqué (8.10, 0, ...)
            $table->integer('vat_amount_cents')->default(0)->after('vat_rate');                // montant TVA en cents
            $table->integer('amount_ht_cents')->default(0)->after('vat_amount_cents');         // sous-total HT en cents
            $table->integer('amount_ttc_cents')->default(0)->after('amount_ht_cents');         // total TTC en cents
            $table->string('vat_treatment', 30)->nullable()->after('amount_ttc_cents');        // ch_standard | eu_reverse_charge | export | none
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'country', 'customer_type', 'vat_number', 'vat_validation_status', 'vat_validated_at',
                'vat_rate', 'vat_amount_cents', 'amount_ht_cents', 'amount_ttc_cents', 'vat_treatment',
            ]);
        });
    }
};
