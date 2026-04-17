<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jalons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('projets')->cascadeOnDelete();
            $table->string('libelle');
            $table->decimal('montant', 12, 2)->default(0);
            $table->date('date')->nullable();
            $table->enum('statut', ['planned', 'sent', 'paid'])->default('planned');
            $table->timestamps();

            $table->index(['projet_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jalons');
    }
};
