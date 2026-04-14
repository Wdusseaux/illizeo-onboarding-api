<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The 'roles' table may already exist (from Spatie or prior setup).
        // Add our custom columns if the table exists, or create it fresh.
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                if (!Schema::hasColumn('roles', 'nom')) $table->string('nom')->nullable()->after('id');
                if (!Schema::hasColumn('roles', 'slug')) $table->string('slug')->nullable()->after('nom');
                if (!Schema::hasColumn('roles', 'description')) $table->text('description')->nullable()->after('slug');
                if (!Schema::hasColumn('roles', 'couleur')) $table->string('couleur', 20)->default('#C2185B')->after('description');
                if (!Schema::hasColumn('roles', 'is_system')) $table->boolean('is_system')->default(false)->after('couleur');
                if (!Schema::hasColumn('roles', 'is_default')) $table->boolean('is_default')->default(false)->after('is_system');
                if (!Schema::hasColumn('roles', 'scope_type')) $table->string('scope_type')->default('global')->after('is_default');
                if (!Schema::hasColumn('roles', 'scope_values')) $table->json('scope_values')->nullable()->after('scope_type');
                if (!Schema::hasColumn('roles', 'temporary')) $table->boolean('temporary')->default(false)->after('scope_values');
                if (!Schema::hasColumn('roles', 'expires_at')) $table->timestamp('expires_at')->nullable()->after('temporary');
                if (!Schema::hasColumn('roles', 'permissions')) $table->json('permissions')->nullable()->after('expires_at');
                if (!Schema::hasColumn('roles', 'ordre')) $table->integer('ordre')->default(0)->after('permissions');
                if (!Schema::hasColumn('roles', 'actif')) $table->boolean('actif')->default(true)->after('ordre');
            });
        } else {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('nom');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('couleur', 20)->default('#C2185B');
                $table->boolean('is_system')->default(false);
                $table->boolean('is_default')->default(false);
                $table->string('scope_type')->default('global');
                $table->json('scope_values')->nullable();
                $table->boolean('temporary')->default(false);
                $table->timestamp('expires_at')->nullable();
                $table->json('permissions')->nullable();
                $table->integer('ordre')->default(0);
                $table->boolean('actif')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('permission_logs')) {
            Schema::create('permission_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action');
                $table->json('details')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_logs');
        Schema::dropIfExists('role_user');
        // Don't drop roles table as it may have been created by Spatie
    }
};
