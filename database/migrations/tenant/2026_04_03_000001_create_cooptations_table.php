<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooptations', function (Blueprint $table) {
            $table->id();
            $table->string('referrer_name');
            $table->string('referrer_email');
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('candidate_name');
            $table->string('candidate_email');
            $table->string('candidate_poste')->nullable();
            $table->foreignId('collaborateur_id')->nullable()->constrained('collaborateurs')->nullOnDelete();
            $table->date('date_cooptation');
            $table->date('date_embauche')->nullable();
            $table->integer('mois_requis')->default(6);
            $table->date('date_validation')->nullable();
            $table->string('statut')->default('en_attente');
            $table->string('type_recompense')->default('prime');
            $table->decimal('montant_recompense', 10, 2)->nullable();
            $table->string('description_recompense')->nullable();
            $table->boolean('recompense_versee')->default(false);
            $table->date('date_versement')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooptations');
    }
};
