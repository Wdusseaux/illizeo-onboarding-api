<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $fillable = [
        'type', 'user_id', 'collaborateur_id', 'model',
        'input_tokens', 'output_tokens', 'cost_usd', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'cost_usd' => 'decimal:6',
    ];

    /**
     * Get monthly count for a given type.
     */
    public static function monthlyCount(string $type, ?int $year = null, ?int $month = null): int
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;

        return static::where('type', $type)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count();
    }

    /**
     * Get all monthly counts for the current month.
     */
    public static function currentMonthSummary(): array
    {
        $year = now()->year;
        $month = now()->month;

        return [
            'ocr_scans' => static::monthlyCount('ocr_scan', $year, $month),
            'bot_messages' => static::monthlyCount('bot_message', $year, $month),
            'contrat_generations' => static::monthlyCount('contrat_generation', $year, $month),
            'total_cost_usd' => (float) static::whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->sum('cost_usd'),
        ];
    }

    /**
     * Check if a quota is exceeded for a given type.
     */
    public static function isQuotaExceeded(string $type, int $limit): bool
    {
        if ($limit <= 0) return false; // 0 = feature disabled
        return static::monthlyCount($type) >= $limit;
    }
}
