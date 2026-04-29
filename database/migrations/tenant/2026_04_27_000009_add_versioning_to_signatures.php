<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add version tracking so a re-uploaded signature_document invalidates
     * previously signed acknowledgements without losing the audit history.
     */
    public function up(): void
    {
        Schema::table('signature_documents', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('actif');
        });

        Schema::table('document_acknowledgements', function (Blueprint $table) {
            $table->unsignedInteger('signed_version')->nullable()->after('signed_at');
        });

        // Backfill: existing docs are version 1; any existing signed ack was for v1.
        \Illuminate\Support\Facades\DB::table('signature_documents')->update(['version' => 1]);
        \Illuminate\Support\Facades\DB::table('document_acknowledgements')
            ->whereIn('statut', ['lu', 'signe'])
            ->update(['signed_version' => 1]);
    }

    public function down(): void
    {
        Schema::table('signature_documents', function (Blueprint $table) {
            $table->dropColumn('version');
        });
        Schema::table('document_acknowledgements', function (Blueprint $table) {
            $table->dropColumn('signed_version');
        });
    }
};
