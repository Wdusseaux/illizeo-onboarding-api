<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status')->default('active');
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('currency')->default('eur');
            $table->string('billing_cycle')->default('monthly');
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->integer('nombre_collaborateurs')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
