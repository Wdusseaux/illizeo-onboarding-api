<?php

use Illuminate\Support\Facades\Route;

// Serve the React SPA for all non-API routes
Route::get('/{any?}', function () {
    $buildPath = public_path('build/index.html');
    if (file_exists($buildPath)) {
        return response()->file($buildPath);
    }
    // Fallback if frontend not built yet
    return response()->json(['message' => 'Illizeo Onboarding API', 'frontend' => 'Build the frontend and place in public/build/']);
})->where('any', '^(?!api/).*$');
