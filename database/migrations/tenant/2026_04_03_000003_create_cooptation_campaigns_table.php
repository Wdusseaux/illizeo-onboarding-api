<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooptation_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->text('description')->nullable();
            $table->string('departement')->nullable();
            $table->string('site')->nullable();
            $table->string('type_contrat')->default('CDI');
            $table->string('type_recompense')->default('prime');
            $table->decimal('montant_recompense', 10, 2)->nullable();
            $table->string('description_recompense')->nullable();
            $table->integer('mois_requis')->default(6);
            $table->string('statut')->default('active');
            $table->date('date_limite')->nullable();
            $table->integer('nombre_postes')->default(1);
            $table->integer('nombre_candidatures')->default(0);
            $table->string('priorite')->default('normale');
            $table->string('share_token')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooptation_campaigns');
    }
};
