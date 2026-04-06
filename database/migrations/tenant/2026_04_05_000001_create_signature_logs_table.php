<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collaborateur_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider'); // docusign, ugosign
            $table->string('envelope_id')->nullable(); // external envelope/contract ID
            $table->string('document_name')->nullable();
            $table->string('status')->default('sent'); // sent, signed, declined, expired
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_logs');
    }
};
