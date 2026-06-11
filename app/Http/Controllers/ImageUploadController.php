<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ImageUploadController extends Controller
{
    public function upload(Request $request)
    {
        // 1. Validasi Signed URL
        $expires = $request->input('expires');
        $signature = $request->input('signature');

        if (!$expires || !$signature) {
            return response()->json(['error' => 'Missing authentication parameters (expires, signature).'], 401);
        }

        if (time() > (int) $expires) {
            return response()->json(['error' => 'Upload URL has expired.'], 403);
        }

        $secret = env('IMAGE_UPLOAD_SECRET');
        if (!$secret) {
            return response()->json(['error' => 'Server configuration error: missing secret.'], 500);
        }

        // Expected signature
        $expectedSignature = hash_hmac('sha256', "expires={$expires}", $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        // 2. Validasi File Gambar
        $request->validate([
            'image' => 'required|image|max:10240', // Max 10MB
        ]);

        $file = $request->file('image');
        
        // Use Intervention Image to convert to webp
        $manager = new ImageManager(new Driver());
        $image = $manager->read($file->getRealPath());
        
        // Encode to webp format with 80% quality
        $encoded = $image->toWebp(80);

        // Generate unique filename
        $filename = Str::uuid() . '.webp';
        
        // Store the file in the public disk
        Storage::disk('public')->put($filename, (string) $encoded);

        // Return the public URL
        return response()->json([
            'success' => true,
            'url' => config('app.url') . Storage::url($filename),
            'path' => $filename
        ]);
    }
}
