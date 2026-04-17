<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projets', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('nom');
            $table->string('code', 50)->unique();
            $table->enum('statut', ['actif', 'archive', 'brouillon'])->default('brouillon');
            $table->string('couleur', 7)->default('#3b82f6'); // Hex color

            // Client
            $table->enum('client_type', ['internal', 'external'])->default('internal');
            $table->string('client')->nullable();
            $table->string('contact_prenom')->nullable();
            $table->string('contact_nom')->nullable();
            $table->string('societe')->nullable();
            $table->string('adresse_client')->nullable();
            $table->string('email_client')->nullable();

            // Dates
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();

            // Contenu
            $table->text('description')->nullable();

            // Finances
            $table->string('devise', 3)->default('CHF');
            $table->boolean('est_facturable')->default(false);
            $table->enum('type_budget', ['none', 'hours', 'cost'])->default('none');
            $table->decimal('valeur_budget', 12, 2)->default(0);
            $table->decimal('prix_vente', 12, 2)->default(0);

            // Roles métier par membre (orthogonal au RBAC technique)
            // Format: { "1": "owner", "2": "editor", ... } où la clé est user_id
            // TODO [RBAC integration]: à terme, pourra mapper aux rôles globaux Illizeo
            $table->json('member_roles')->nullable();

            $table->timestamps();

            $table->index('statut');
            $table->index(['statut', 'date_debut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projets');
    }
};
