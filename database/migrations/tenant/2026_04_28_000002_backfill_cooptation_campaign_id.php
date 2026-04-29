<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Historical cooptations were created with campaign_id = NULL because the
// validation rule was missing in the API controller. Match each orphan
// cooptation to a campaign by case-insensitive trimmed titre/candidate_poste,
// and only when there's exactly one candidate campaign so we never assign
// the wrong one. Multiple-match rows stay NULL — the admin can fix manually.

return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('cooptations')
            ->whereNull('campaign_id')
            ->whereNotNull('candidate_poste')
            ->where('candidate_poste', '!=', '')
            ->select('id', 'candidate_poste')
            ->get();

        $updated = 0;
        foreach ($orphans as $row) {
            $needle = trim(strtolower($row->candidate_poste));
            if ($needle === '') continue;
            $matches = DB::table('cooptation_campaigns')
                ->whereRaw('LOWER(TRIM(titre)) = ?', [$needle])
                ->pluck('id');
            if ($matches->count() === 1) {
                DB::table('cooptations')
                    ->where('id', $row->id)
                    ->update(['campaign_id' => $matches->first()]);
                $updated++;
            }
        }
        // Surface the count in the migration log.
        if (function_exists('logger')) logger("Cooptation campaign backfill: {$updated}/{$orphans->count()} rows linked");
    }

    public function down(): void
    {
        // No-op — we don't want to wipe valid associations on rollback.
        // If you really need to undo, do it manually with a SQL query you control.
    }
};
