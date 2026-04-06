<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('target_group_id')->nullable();
            $table->string('badge_name')->nullable();
            $table->string('badge_icon')->nullable();
            $table->string('badge_color')->nullable();
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->text('bot_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_user_id');
            $table->dropColumn([
                'target_group_id', 'badge_name', 'badge_icon', 'badge_color',
                'email_subject', 'email_body', 'bot_message',
            ]);
        });
    }
};
