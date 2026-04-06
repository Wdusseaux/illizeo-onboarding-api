<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // docusign, ugosign, slack, microsoft365, google, smartrecruiters, sap
            $table->string('categorie'); // signature, communication, sirh, ats, stockage
            $table->string('nom');
            $table->json('config')->nullable(); // { client_id, client_secret, api_key, webhook_url, environment... }
            $table->boolean('actif')->default(false);
            $table->boolean('connecte')->default(false);
            $table->timestamp('derniere_sync')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
