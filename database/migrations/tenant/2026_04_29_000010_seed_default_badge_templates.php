<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the default badge_templates catalogue on existing tenants.
 * Idempotent: only inserts a badge if no template with the same `critere` already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('badge_templates')) {
            return;
        }

        $defaults = [
            ['nom' => 'Explorateur', 'description' => "Premier message envoyé — bienvenue dans l'aventure !", 'icon' => 'rocket', 'color' => '#9C27B0', 'critere' => 'premier_message'],
            ['nom' => 'Intégré', 'description' => 'Dossier administratif complet — tous les documents validés.', 'icon' => 'check-circle', 'color' => '#4CAF50', 'critere' => 'docs_complete'],
            ['nom' => 'Voix entendue', 'description' => 'Premier feedback NPS partagé — merci pour votre avis !', 'icon' => 'message-circle', 'color' => '#1A73E8', 'critere' => 'nps_complete'],
            ['nom' => 'Mentor', 'description' => 'A coopté un nouveau talent — merci pour votre engagement.', 'icon' => 'users', 'color' => '#FF9800', 'critere' => 'cooptation'],
            ['nom' => 'Champion', 'description' => "Parcours d'intégration terminé à 100% — bravo !", 'icon' => 'trophy', 'color' => '#F9A825', 'critere' => 'parcours_termine'],
        ];

        foreach ($defaults as $bt) {
            $exists = DB::table('badge_templates')->where('critere', $bt['critere'])->exists();
            if ($exists) {
                continue;
            }
            DB::table('badge_templates')->insert(array_merge($bt, [
                'actif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('badge_templates')) {
            return;
        }
        DB::table('badge_templates')
            ->whereIn('critere', ['premier_message', 'docs_complete', 'nps_complete', 'cooptation', 'parcours_termine'])
            ->delete();
    }
};
