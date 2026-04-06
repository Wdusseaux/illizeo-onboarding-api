<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooptation_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referrer_email');
            $table->string('referrer_name');
            $table->foreignId('cooptation_id')->constrained('cooptations')->cascadeOnDelete();
            $table->integer('points');
            $table->string('motif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooptation_points');
    }
};
