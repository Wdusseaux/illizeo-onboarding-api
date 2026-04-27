<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    /**
     * List all quotes (system + tenant). Used by admin page.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Quote::orderByRaw("source = 'tenant' DESC")
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Get the quote of the day (deterministic per day, only active ones).
     * Public read for the employee dashboard.
     */
    public function ofTheDay(): JsonResponse
    {
        $active = Quote::where('actif', true)->orderBy('id')->get();
        if ($active->isEmpty()) {
            return response()->json(null);
        }
        $idx = (int) now()->format('z') % $active->count();
        return response()->json($active[$idx]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string|max:1000',
            'author' => 'nullable|string|max:255',
            'translations' => 'nullable|array',
        ]);
        $data['source'] = 'tenant';
        $data['actif'] = true;
        $quote = Quote::create($data);
        return response()->json($quote, 201);
    }

    public function update(Request $request, Quote $quote): JsonResponse
    {
        // System quotes: only the actif flag can be changed (preserve referential integrity)
        if ($quote->source === 'system') {
            $data = $request->validate(['actif' => 'boolean']);
        } else {
            $data = $request->validate([
                'text' => 'sometimes|required|string|max:1000',
                'author' => 'nullable|string|max:255',
                'actif' => 'sometimes|boolean',
                'translations' => 'nullable|array',
            ]);
        }
        $quote->update($data);
        return response()->json($quote);
    }

    public function toggle(Quote $quote): JsonResponse
    {
        $quote->update(['actif' => !$quote->actif]);
        return response()->json($quote);
    }

    public function destroy(Quote $quote): JsonResponse
    {
        if ($quote->source === 'system') {
            return response()->json([
                'error' => 'Les citations du référentiel ne peuvent pas être supprimées. Désactivez-la à la place.',
            ], 403);
        }
        $quote->delete();
        return response()->json(['ok' => true]);
    }
}
