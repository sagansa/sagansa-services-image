<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// Serve storage files via Laravel so CORS middleware applies.
// This route handles requests to /storage/{path} with proper CORS headers.
// NOTE: On production (Hostinger), the web server may serve static files
// from public/storage directly before reaching Laravel. To force requests
// through this route, a rewrite rule is needed in .htaccess.
Route::get('/storage/{path}', function (string $path) {
    // Prevent directory traversal
    if (str_contains($path, '..')) {
        abort(403);
    }

    if (! Storage::disk('public')->exists($path)) {
        abort(404);
    }

    $mimeType = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';

    return response()->stream(function () use ($path) {
        echo Storage::disk('public')->get($path);
    }, 200, [
        'Content-Type'                => $mimeType,
        'Cache-Control'               => 'public, max-age=604800',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->where('path', '.*');
