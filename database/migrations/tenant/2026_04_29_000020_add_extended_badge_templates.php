<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the 4 extended badges to existing tenants.
 * Idempotent: only inserts a badge if no template with the same `critere` already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('badge_templates')) {
            return;
        }

        $extras = [
            ['nom' => 'Première semaine', 'description' => "7 jours dans l'équipe — vous avez survécu à la première semaine !", 'icon' => 'calendar-check', 'color' => '#26A69A', 'critere' => 'j_plus_7'],
            ['nom' => 'Apprenant', 'description' => "Première formation terminée — la connaissance c'est le pouvoir.", 'icon' => 'book-open', 'color' => '#7B5EA7', 'critere' => 'formation_complete'],
            ['nom' => 'Ambassadeur', 'description' => '3 cooptations validées — vous êtes un véritable ambassadeur Illizeo.', 'icon' => 'award', 'color' => '#D81B60', 'critere' => 'cooptation_3'],
            ['nom' => 'Cap des 100j', 'description' => "100 jours dans l'aventure — un vrai pilier de l'équipe.", 'icon' => 'target', 'color' => '#EF6C00', 'critere' => 'j_plus_100'],
        ];

        foreach ($extras as $bt) {
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
            ->whereIn('critere', ['j_plus_7', 'formation_complete', 'cooptation_3', 'j_plus_100'])
            ->delete();
    }
};
