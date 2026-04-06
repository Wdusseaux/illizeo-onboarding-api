<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_types', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('icon')->default('package');
            $table->string('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('equipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('numero_serie')->nullable();
            $table->string('marque')->nullable();
            $table->string('modele')->nullable();
            $table->enum('etat', ['disponible', 'attribue', 'en_commande', 'en_reparation', 'retire'])->default('disponible');
            $table->foreignId('collaborateur_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->date('date_achat')->nullable();
            $table->decimal('valeur', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('signature_documents', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->text('description')->nullable();
            $table->enum('type', ['lecture', 'signature'])->default('lecture');
            $table->string('fichier_path')->nullable();
            $table->string('fichier_nom')->nullable();
            $table->boolean('obligatoire')->default(true);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collaborateur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('statut', ['en_attente', 'lu', 'signe', 'refuse'])->default('en_attente');
            $table->timestamp('signed_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgements');
        Schema::dropIfExists('signature_documents');
        Schema::dropIfExists('equipments');
        Schema::dropIfExists('equipment_types');
    }
};
