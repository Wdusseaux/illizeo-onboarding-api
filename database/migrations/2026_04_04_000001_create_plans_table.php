<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('prix_eur_mensuel', 8, 2);
            $table->decimal('prix_chf_mensuel', 8, 2);
            $table->decimal('min_mensuel_eur', 8, 2)->default(0);
            $table->decimal('min_mensuel_chf', 8, 2)->default(0);
            $table->integer('max_collaborateurs')->nullable();
            $table->integer('max_admins')->nullable();
            $table->integer('max_parcours')->nullable();
            $table->integer('max_integrations')->nullable();
            $table->integer('max_workflows')->nullable();
            $table->string('stripe_price_id_eur')->nullable();
            $table->string('stripe_price_id_chf')->nullable();
            $table->boolean('actif')->default(true);
            $table->boolean('populaire')->default(false);
            $table->integer('ordre')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
