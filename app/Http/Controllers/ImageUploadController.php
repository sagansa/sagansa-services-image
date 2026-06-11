<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImageUploadController extends Controller
{
    public function upload(Request $request)
    {
        try {
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
                Log::error('ImageUpload: IMAGE_UPLOAD_SECRET is not set in .env');
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

            // Optional directory (e.g. "ops/product", "ops/attendance")
            $directory = trim($request->input('directory', ''), '/');
            if ($directory) {
                // Validate directory contains only safe characters
                if (!preg_match('/^[a-zA-Z0-9_\-\/]+$/', $directory)) {
                    return response()->json(['error' => 'Invalid directory format.'], 422);
                }
            }

            // Use Intervention Image to convert to webp
            $manager = new ImageManager(Driver::class);
            $image = $manager->decodePath($file->getRealPath());

            // Encode to webp format with 80% quality
            $encoded = $image->encode(new WebpEncoder(80));

            // Generate unique filename
            $filename = Str::uuid() . '.webp';

            // Store the file in the public disk under the directory
            $storagePath = $directory ? "{$directory}/{$filename}" : $filename;
            Storage::disk('public')->put($storagePath, (string) $encoded);

            // Return the public URL
            return response()->json([
                'success' => true,
                'url' => config('app.url') . Storage::url($storagePath),
                'path' => $storagePath
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed.',
                'details' => $e->errors(),
            ], 422);
        } catch (\Intervention\Image\Exceptions\DecoderException $e) {
            Log::error('ImageUpload: Decoder error - ' . $e->getMessage());
            return response()->json([
                'error' => 'Image decoding failed.',
                'details' => $e->getMessage(),
            ], 422);
        } catch (\Intervention\Image\Exceptions\EncoderException $e) {
            Log::error('ImageUpload: Encoder error - ' . $e->getMessage());
            return response()->json([
                'error' => 'Image encoding to WebP failed. Is the GD extension installed?',
                'details' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('ImageUpload: ' . get_class($e) . ' - ' . $e->getMessage());
            return response()->json([
                'error' => 'Upload failed.',
                'details' => env('APP_DEBUG', false) ? $e->getMessage() : 'Internal server error.',
            ], 500);
        }
    }
}
