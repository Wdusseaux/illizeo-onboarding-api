<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuddyPair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuddyPairController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            BuddyPair::with(['newcomer', 'buddy', 'assignedBy'])
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'newcomer_id' => 'required|exists:collaborateurs,id',
            'buddy_id' => 'required|exists:collaborateurs,id',
            'status' => 'nullable|in:active,completed,cancelled',
        ]);

        // Check for existing active pair with same newcomer
        $existing = BuddyPair::where('newcomer_id', $validated['newcomer_id'])
            ->where('status', 'active')
            ->first();
        if ($existing) {
            return response()->json([
                'error' => 'Ce collaborateur a déjà un buddy actif',
            ], 422);
        }

        $validated['assigned_by'] = $request->user()->id;
        $validated['checklist'] = [false, false, false, false, false, false, false, false];

        $pair = BuddyPair::create($validated);

        return response()->json($pair->load(['newcomer', 'buddy', 'assignedBy']), 201);
    }

    public function show(BuddyPair $buddyPair): JsonResponse
    {
        return response()->json(
            $buddyPair->load(['newcomer', 'buddy', 'assignedBy'])
        );
    }

    public function update(Request $request, BuddyPair $buddyPair): JsonResponse
    {
        $validated = $request->validate([
            'checklist' => 'nullable|array',
            'notes' => 'nullable|array',
            'rating' => 'nullable|numeric|min:1|max:5',
            'feedback_comment' => 'nullable|string',
            'status' => 'nullable|in:active,completed,cancelled',
        ]);

        $buddyPair->update($validated);

        return response()->json($buddyPair->load(['newcomer', 'buddy', 'assignedBy']));
    }

    public function destroy(BuddyPair $buddyPair): JsonResponse
    {
        $buddyPair->delete();

        return response()->json(null, 204);
    }

    public function addNote(Request $request, BuddyPair $buddyPair): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string',
        ]);

        $notes = $buddyPair->notes ?? [];
        $notes[] = [
            'text' => $validated['text'],
            'date' => now()->toISOString(),
        ];

        $buddyPair->update(['notes' => $notes]);

        return response()->json($buddyPair->load(['newcomer', 'buddy', 'assignedBy']));
    }

    public function complete(BuddyPair $buddyPair): JsonResponse
    {
        $buddyPair->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json($buddyPair->load(['newcomer', 'buddy', 'assignedBy']));
    }
}
