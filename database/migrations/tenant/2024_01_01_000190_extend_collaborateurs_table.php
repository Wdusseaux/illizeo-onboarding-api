<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborateurs', function (Blueprint $table) {
            // Personal
            $table->string('civilite')->nullable()->after('couleur'); // M., Mme
            $table->date('date_naissance')->nullable();
            $table->string('nationalite')->nullable();
            $table->string('numero_avs')->nullable();
            $table->string('telephone')->nullable();
            $table->text('adresse')->nullable();
            $table->string('ville')->nullable();
            $table->string('code_postal')->nullable();
            $table->string('pays')->nullable();
            $table->string('iban')->nullable();
            // Contract
            $table->string('type_contrat')->nullable(); // CDI, CDD, Stage...
            $table->decimal('salaire_brut', 10, 2)->nullable();
            $table->string('devise')->default('CHF');
            $table->integer('taux_activite')->default(100); // %
            $table->string('periode_essai')->nullable();
            $table->date('date_fin_essai')->nullable();
            $table->string('convention_collective')->nullable();
            $table->string('duree_contrat')->nullable(); // For CDD
            // Org
            $table->string('matricule')->nullable();
            $table->string('manager_nom')->nullable();
            $table->string('centre_cout')->nullable();
            $table->string('entite_juridique')->nullable();
            $table->string('categorie_pro')->nullable();
            $table->string('niveau_hierarchique')->nullable();
            $table->string('recruteur')->nullable();
            $table->json('custom_fields')->nullable();
        });

        // Table to configure which fields are active/required per tenant
        Schema::create('collaborateur_field_config', function (Blueprint $table) {
            $table->id();
            $table->string('field_key')->unique();
            $table->string('label');
            $table->string('section'); // personal, contract, org
            $table->boolean('actif')->default(true);
            $table->boolean('obligatoire')->default(false);
            $table->integer('ordre')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborateur_field_config');
        Schema::table('collaborateurs', function (Blueprint $table) {
            $table->dropColumn([
                'civilite', 'date_naissance', 'nationalite', 'numero_avs', 'telephone',
                'adresse', 'ville', 'code_postal', 'pays', 'iban',
                'type_contrat', 'salaire_brut', 'devise', 'taux_activite', 'periode_essai',
                'date_fin_essai', 'convention_collective', 'duree_contrat',
                'matricule', 'manager_nom', 'centre_cout', 'entite_juridique',
                'categorie_pro', 'niveau_hierarchique', 'recruteur',
            ]);
        });
    }
};
