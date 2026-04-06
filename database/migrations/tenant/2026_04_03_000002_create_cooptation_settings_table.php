<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooptation_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('mois_requis_defaut')->default(6);
            $table->decimal('montant_defaut', 10, 2)->default(500.00);
            $table->string('type_recompense_defaut')->default('prime');
            $table->string('description_recompense_defaut')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooptation_settings');
    }
};
