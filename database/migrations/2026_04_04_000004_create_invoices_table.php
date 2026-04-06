<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->string('stripe_invoice_id')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('currency');
            $table->string('status')->default('draft');
            $table->date('date_emission');
            $table->date('date_echeance');
            $table->string('pdf_url')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
