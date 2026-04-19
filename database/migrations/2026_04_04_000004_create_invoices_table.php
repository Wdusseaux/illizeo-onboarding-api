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
            $table->string('invoice_number')->unique();
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_invoice_id')->nullable();

            // Amounts
            $table->decimal('montant_ht', 10, 2); // Amount before tax
            $table->decimal('taux_tva', 5, 2)->default(0); // TVA rate (8.1 for Swiss, 0 otherwise)
            $table->decimal('montant_tva', 10, 2)->default(0);
            $table->decimal('montant_ttc', 10, 2); // Total including tax
            $table->decimal('prorata_credit', 10, 2)->default(0);
            $table->string('currency')->default('chf');

            // Billing details
            $table->string('payment_method')->default('invoice'); // stripe, sepa, invoice
            $table->integer('nombre_collaborateurs')->default(25);
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly
            $table->date('period_start');
            $table->date('period_end');

            // Status
            $table->string('status')->default('draft'); // draft, sent, paid, failed, canceled, refunded
            $table->date('date_emission');
            $table->date('date_echeance');
            $table->timestamp('paid_at')->nullable();
            $table->integer('payment_attempts')->default(0);
            $table->timestamp('last_payment_attempt')->nullable();
            $table->text('payment_error')->nullable();

            // PDF
            $table->string('pdf_path')->nullable();

            // Billing info snapshot (frozen at invoice time)
            $table->json('billing_snapshot')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'date_echeance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
