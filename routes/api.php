<?php

use App\Http\Controllers\ImageUploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Semua endpoint dilindungi Sanctum. Token yang sama dengan api-ops (login)
// langsung valid di sini karena membaca personal_access_tokens di sagansa_user.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Upload gambar (rate limited: 30 request/menit per token)
    Route::post('/upload', [ImageUploadController::class, 'upload'])
        ->middleware('throttle:30,1');

    // Hapus gambar by relative path
    Route::delete('/images', [ImageUploadController::class, 'delete'])
        ->middleware('throttle:30,1');
});
