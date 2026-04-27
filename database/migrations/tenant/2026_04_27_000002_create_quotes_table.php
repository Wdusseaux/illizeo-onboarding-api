<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->string('author')->nullable();
            // Source: 'system' (seeded referential, can be deactivated but not deleted)
            // or 'tenant' (added by client, can be deleted)
            $table->string('source')->default('tenant');
            $table->boolean('actif')->default(true);
            $table->json('translations')->nullable();
            $table->timestamps();

            $table->index(['actif', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
