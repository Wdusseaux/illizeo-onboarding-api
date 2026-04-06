<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaborateurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('prenom');
            $table->string('nom');
            $table->string('email')->unique();
            $table->string('poste')->nullable();
            $table->string('site')->nullable();
            $table->string('departement')->nullable();
            $table->date('date_debut')->nullable();
            $table->string('phase')->nullable();
            $table->integer('progression')->default(0);
            $table->enum('status', ['en_cours', 'en_retard', 'termine'])->default('en_cours');
            $table->integer('docs_valides')->default(0);
            $table->integer('docs_total')->default(0);
            $table->integer('actions_completes')->default(0);
            $table->integer('actions_total')->default(0);
            $table->string('initials', 5)->nullable();
            $table->string('couleur', 10)->default('#C2185B');
            $table->string('photo')->nullable();
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborateurs');
    }
};
