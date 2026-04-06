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
            $table->unsignedBigInteger('plan_id')->nullable()->after('plan');
            $table->string('stripe_customer_id')->nullable()->after('plan_id');
            $table->date('trial_ends_at')->nullable()->after('stripe_customer_id');
            $table->string('billing_email')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['plan_id', 'stripe_customer_id', 'trial_ends_at', 'billing_email']);
        });
    }
};
