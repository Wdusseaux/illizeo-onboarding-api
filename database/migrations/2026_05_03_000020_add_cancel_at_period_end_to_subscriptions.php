<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'cancel_at_period_end')) {
                $table->boolean('cancel_at_period_end')->default(false)->after('canceled_at');
            }
            if (!Schema::hasColumn('subscriptions', 'next_payment_amount_cents')) {
                $table->integer('next_payment_amount_cents')->nullable()->after('cancel_at_period_end');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'cancel_at_period_end')) {
                $table->dropColumn('cancel_at_period_end');
            }
            if (Schema::hasColumn('subscriptions', 'next_payment_amount_cents')) {
                $table->dropColumn('next_payment_amount_cents');
            }
        });
    }
};
