<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_accesses', function (Blueprint $table) {
            $table->id();
            $table->string('email'); // Illizeo support email (e.g. support@illizeo.com)
            $table->string('access_token', 64)->unique(); // Secure token for login
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete(); // Client admin who granted
            $table->json('allowed_modules')->nullable(); // null = all, or ["onboarding","offboarding"]
            $table->string('reason')->nullable(); // Why access was granted
            $table->timestamp('expires_at'); // Auto-expiry
            $table->timestamp('revoked_at')->nullable(); // Manual revocation
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('access_token');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_accesses');
    }
};
