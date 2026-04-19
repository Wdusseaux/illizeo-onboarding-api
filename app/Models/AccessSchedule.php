<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AccessSchedule extends Model
{
    protected $fillable = ['label', 'days', 'start_time', 'end_time', 'timezone', 'actif'];

    protected $casts = [
        'days' => 'array',
        'actif' => 'boolean',
    ];

    /**
     * Check if current time is within any active schedule.
     * Returns true if access is allowed, false if blocked.
     * If no active schedules exist, access is always allowed.
     */
    public static function isAccessAllowed(): bool
    {
        $schedules = static::where('actif', true)->get();
        if ($schedules->isEmpty()) return true; // No restriction

        foreach ($schedules as $schedule) {
            $now = Carbon::now($schedule->timezone);
            $dayOfWeek = $now->dayOfWeekIso; // 1=Mon, 7=Sun

            if (in_array($dayOfWeek, $schedule->days)) {
                $start = Carbon::parse($schedule->start_time, $schedule->timezone);
                $end = Carbon::parse($schedule->end_time, $schedule->timezone);

                if ($now->between($start, $end)) {
                    return true;
                }
            }
        }

        return false;
    }
}
