<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('sujet');
            $table->string('declencheur');
            $table->json('variables')->nullable(); // ["{{prenom}}", "{{date_debut}}"]
            $table->boolean('actif')->default(true);
            $table->text('contenu')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
