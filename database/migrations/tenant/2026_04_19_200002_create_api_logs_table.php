<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('method', 10);
            $table->string('endpoint');
            $table->integer('status_code');
            $table->integer('response_time_ms');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('api_key_id')->references('id')->on('api_keys')->nullOnDelete();
            $table->index('api_key_id');
            $table->index('created_at');
            $table->index('status_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
