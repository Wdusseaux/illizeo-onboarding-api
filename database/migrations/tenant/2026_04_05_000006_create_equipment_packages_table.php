<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'categorie' to equipment_types (materiel vs licence)
        Schema::table('equipment_types', function (Blueprint $table) {
            $table->enum('categorie', ['materiel', 'licence'])->default('materiel')->after('icon');
        });

        Schema::create('equipment_packages', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('icon')->default('package');
            $table->string('couleur')->default('#C2185B');
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('equipment_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_type_id')->constrained()->cascadeOnDelete();
            $table->integer('quantite')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_package_items');
        Schema::dropIfExists('equipment_packages');
        Schema::table('equipment_types', function (Blueprint $table) {
            $table->dropColumn('categorie');
        });
    }
};
