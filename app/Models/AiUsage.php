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

        // Detailed breakdown by context (from metadata)
        $allEntries = static::whereYear('created_at', $year)->whereMonth('created_at', $month)->get();

        $chatEmployee = $allEntries->filter(fn($e) => $e->type === 'bot_message' && (($e->metadata['context'] ?? '') === '' || !isset($e->metadata['context'])))->count();
        $chatAdmin = $allEntries->filter(fn($e) => ($e->metadata['context'] ?? '') === 'admin_chat')->count();
        $insights = $allEntries->filter(fn($e) => ($e->metadata['context'] ?? '') === 'insights')->count();
        $generateParcours = $allEntries->filter(fn($e) => ($e->metadata['context'] ?? '') === 'generate_parcours')->count();

        return [
            'ocr_scans' => static::monthlyCount('ocr_scan', $year, $month),
            'bot_messages' => static::monthlyCount('bot_message', $year, $month),
            'contrat_generations' => static::monthlyCount('contrat_generation', $year, $month),
            'chat_employee' => $chatEmployee,
            'chat_admin' => $chatAdmin,
            'insights' => $insights,
            'generate_parcours' => $generateParcours,
            'total_cost_usd' => (float) $allEntries->sum('cost_usd'),
            'total_input_tokens' => (int) $allEntries->sum('input_tokens'),
            'total_output_tokens' => (int) $allEntries->sum('output_tokens'),
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
