<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('collaborateur_id')->constrained('users')->nullOnDelete();
            $table->string('fichier_original')->nullable()->after('fichier_path');
            $table->unsignedInteger('fichier_taille')->nullable()->after('fichier_original');
            $table->string('fichier_mime')->nullable()->after('fichier_taille');
            $table->foreignId('validated_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable()->after('validated_by');
            $table->string('refuse_motif')->nullable()->after('validated_at');
            $table->text('notes')->nullable()->after('refuse_motif');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('fichier_original');
            $table->dropColumn('fichier_taille');
            $table->dropColumn('fichier_mime');
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn('validated_at');
            $table->dropColumn('refuse_motif');
            $table->dropColumn('notes');
        });
    }
};
