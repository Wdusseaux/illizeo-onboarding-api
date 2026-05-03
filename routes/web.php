<?php

use Illuminate\Support\Facades\Route;

// Public logo endpoint for emails — explicit headers, no CORP/CORS issues
// so Outlook & Gmail image proxies can fetch it reliably.
Route::get('/email-assets/illizeo-logo.png', function () {
    $path = public_path('build/illizeo-Logo-site.png');
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->file($path, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'Cross-Origin-Resource-Policy' => 'cross-origin',
        'Access-Control-Allow-Origin' => '*',
    ]);
});

// Serve the React SPA for all non-API routes
Route::get('/{any?}', function () {
    $buildPath = public_path('build/index.html');
    if (file_exists($buildPath)) {
        // index.html ne doit JAMAIS être caché par le navigateur : il pointe vers les
        // bundles JS hashés (qui peuvent disparaître à chaque déploiement). Si le
        // navigateur garde un vieux index.html, il essaie de charger un JS supprimé,
        // le serveur retombe sur ce SPA fallback (HTML), et la console hurle au MIME.
        return response()->file($buildPath, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
    // Fallback if frontend not built yet
    return response()->json(['message' => 'Illizeo Onboarding API', 'frontend' => 'Build the frontend and place in public/build/']);
})->where('any', '(?!api/).*');
