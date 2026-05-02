<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('billing_email');
            $table->string('customer_type', 20)->nullable()->after('country');
            $table->string('vat_number', 32)->nullable()->after('customer_type');
            $table->string('vat_validation_status', 20)->nullable()->after('vat_number');
            $table->timestamp('vat_validated_at')->nullable()->after('vat_validation_status');
            $table->json('billing_address')->nullable()->after('vat_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'country',
                'customer_type',
                'vat_number',
                'vat_validation_status',
                'vat_validated_at',
                'billing_address',
            ]);
        });
    }
};
