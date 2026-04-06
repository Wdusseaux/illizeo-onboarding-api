<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_logs', function (Blueprint $table) {
            $table->foreignId('signature_document_id')->nullable()->after('id')->constrained('signature_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signature_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signature_document_id');
        });
    }
};
