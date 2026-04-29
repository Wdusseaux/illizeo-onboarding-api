<?php

namespace App\Observers;

use App\Models\Cooptation;
use App\Services\CooptationScoringService;
use App\Services\CvParsingService;
use Illuminate\Support\Facades\Log;

/**
 * Auto-score a cooptation on create and when its CV is uploaded.
 * Runs synchronously — fast enough on Haiku (1-2s). For higher volumes,
 * dispatch via Laravel queue instead.
 */
class CooptationObserver
{
    public function created(Cooptation $cooptation): void
    {
        $this->scoreSafely($cooptation);
    }

    public function updated(Cooptation $cooptation): void
    {
        // Only re-score when meaningful fields change. Avoids loops on
        // priority_* updates (which themselves are triggered by scoring).
        $relevantChanged = $cooptation->wasChanged([
            'cv_path', 'notes', 'candidate_poste', 'campaign_id', 'statut',
        ]);
        if (!$relevantChanged) return;
        $this->scoreSafely($cooptation);
    }

    private function scoreSafely(Cooptation $cooptation): void
    {
        try {
            // CV parsing first if a fresh CV is present and not yet parsed.
            if ($cooptation->cv_path && !$cooptation->cv_parsed_at) {
                app(CvParsingService::class)->parse($cooptation);
                $cooptation->refresh();
            }
            app(CooptationScoringService::class)->score($cooptation);
        } catch (\Throwable $e) {
            Log::warning('CooptationObserver: scoring failed (non-blocking)', ['error' => $e->getMessage(), 'id' => $cooptation->id]);
        }
    }
}
