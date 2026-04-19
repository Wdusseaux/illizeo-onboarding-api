<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Active sessions tracking
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_id')->nullable(); // personal_access_tokens ID
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device')->nullable(); // "Chrome on Windows", "Safari on iPhone"
            $table->string('browser')->nullable();
            $table->string('platform')->nullable(); // Windows, macOS, iOS, Android
            $table->string('location')->nullable(); // City/Country (optional)
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('token_id');
        });

        // Login history
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device')->nullable();
            $table->boolean('success')->default(true);
            $table->string('failure_reason')->nullable(); // wrong_password, account_locked, ip_blocked, 2fa_failed
            $table->string('method')->default('password'); // password, sso, 2fa, support_token
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('email');
        });

        // Access time restrictions
        Schema::create('access_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable(); // "Heures de bureau"
            $table->json('days'); // [1,2,3,4,5] = Mon-Fri
            $table->time('start_time'); // 07:00
            $table->time('end_time'); // 20:00
            $table->string('timezone')->default('Europe/Zurich');
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_schedules');
        Schema::dropIfExists('login_history');
        Schema::dropIfExists('user_sessions');
    }
};
