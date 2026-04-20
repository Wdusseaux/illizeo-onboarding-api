<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recharges', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount_chf', 10, 2);
            $table->integer('credits_added');
            $table->string('trigger')->default('auto'); // auto, manual
            $table->string('status')->default('pending'); // pending, charged, failed
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recharges');
    }
};
