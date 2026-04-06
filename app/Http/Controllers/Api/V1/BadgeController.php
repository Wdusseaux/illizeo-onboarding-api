<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\BadgeTemplate;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    /**
     * List all badge templates with earned count per template.
     */
    public function index(): JsonResponse
    {
        $templates = BadgeTemplate::withCount([
            'badges as earned_count',
        ])->orderBy('nom')->get();

        return response()->json($templates);
    }

    /**
     * List all earned badges (with user info).
     */
    public function earned(): JsonResponse
    {
        $badges = Badge::with('user')
            ->orderByDesc('earned_at')
            ->get();

        return response()->json($badges);
    }

    /**
     * List current user's earned badges.
     */
    public function myBadges(): JsonResponse
    {
        $badges = Badge::where('user_id', auth()->id())
            ->orderByDesc('earned_at')
            ->get();

        return response()->json($badges);
    }

    /**
     * List badges for a specific user.
     */
    public function userBadges(int $userId): JsonResponse
    {
        $badges = Badge::where('user_id', $userId)
            ->orderByDesc('earned_at')
            ->get();

        return response()->json($badges);
    }

    /**
     * List all badge templates (CRUD read).
     */
    public function templates(): JsonResponse
    {
        $templates = BadgeTemplate::orderBy('nom')->get();

        return response()->json($templates);
    }

    /**
     * Create a badge template.
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'critere' => 'nullable|string|in:parcours_complete,docs_complete,premier_message,first_week,first_month,cooptation,nps_complete,manual',
            'actif' => 'nullable|boolean',
        ]);

        $template = BadgeTemplate::create($validated);

        return response()->json($template, 201);
    }

    /**
     * Update a badge template.
     */
    public function updateTemplate(Request $request, BadgeTemplate $badgeTemplate): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'critere' => 'nullable|string|in:parcours_complete,docs_complete,premier_message,first_week,first_month,cooptation,nps_complete,manual',
            'actif' => 'nullable|boolean',
        ]);

        $badgeTemplate->update($validated);

        return response()->json($badgeTemplate);
    }

    /**
     * Delete a badge template.
     */
    public function destroyTemplate(BadgeTemplate $badgeTemplate): JsonResponse
    {
        $badgeTemplate->delete();

        return response()->json(['message' => 'Badge template supprimé.']);
    }

    /**
     * Manually award a badge to a user.
     */
    public function award(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'badge_template_id' => 'nullable|exists:badge_templates,id',
            'nom' => 'required_without:badge_template_id|string|max:255',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
        ]);

        // If a template is provided, use its values
        if (! empty($validated['badge_template_id'])) {
            $template = BadgeTemplate::findOrFail($validated['badge_template_id']);
            $badgeData = [
                'user_id' => $validated['user_id'],
                'nom' => $template->nom,
                'description' => $template->description,
                'icon' => $template->icon,
                'color' => $template->color,
                'earned_at' => now(),
            ];
        } else {
            $badgeData = [
                'user_id' => $validated['user_id'],
                'nom' => $validated['nom'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? 'trophy',
                'color' => $validated['color'] ?? '#F9A825',
                'earned_at' => now(),
            ];
        }

        $badge = Badge::create($badgeData);

        // Send notification
        NotificationService::send(
            $badge->user_id,
            'badge_earned',
            'Nouveau badge !',
            "Vous avez obtenu le badge « {$badge->nom} » !",
            $badge->icon,
            $badge->color,
            ['badge_id' => $badge->id, 'badge_nom' => $badge->nom]
        );

        return response()->json($badge->load('user'), 201);
    }

    /**
     * Revoke (remove) an earned badge.
     */
    public function revoke(Badge $badge): JsonResponse
    {
        $badge->delete();

        return response()->json(['message' => 'Badge retiré.']);
    }
}
