<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class ImageUploadController extends Controller
{
    /**
     * Upload gambar, dikonversi ke WebP, disimpan ke disk public.
     *
     * Dilindungi oleh middleware auth:sanctum (lihat routes/api.php). Token
     * Sanctum yang digunakan adalah token yang sama yang dikeluarkan saat
     * login di api-ops (atau app lain), karena seluruh service membaca tabel
     * personal_access_tokens di sagansa_user.
     */
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|max:10240', // Max 10MB
            ]);

            $file = $request->file('image');

            // Optional directory (e.g. "ops/product", "admin/Product")
            $directory = trim($request->input('directory', ''), '/');
            if ($directory) {
                // Validate directory contains only safe characters (cegah path traversal)
                if (!preg_match('/^[a-zA-Z0-9_\-\/]+$/', $directory)) {
                    return response()->json(['error' => 'Invalid directory format.'], 422);
                }
            }

            // Decode gambar dan re-encode ke WebP (menormalkan payload, cegah polyglot)
            $manager = new ImageManager(Driver::class);
            $image = $manager->decodePath($file->getRealPath());
            $encoded = $image->encode(new WebpEncoder(80));

            // Filename UUID agar tidak ada benturan / injeksi nama
            $filename = Str::uuid() . '.webp';
            $storagePath = $directory ? "{$directory}/{$filename}" : $filename;

            Storage::disk('public')->put($storagePath, (string) $encoded);

            return response()->json([
                'success' => true,
                'url' => rtrim(config('app.url'), '/') . '/storage/' . ltrim($storagePath, '/'),
                'path' => $storagePath,
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
            ], 422);
        } catch (\Intervention\Image\Exceptions\EncoderException $e) {
            Log::error('ImageUpload: Encoder error - ' . $e->getMessage());
            return response()->json([
                'error' => 'Image encoding to WebP failed. Is the GD extension installed?',
            ], 500);
        } catch (\Exception $e) {
            Log::error('ImageUpload: ' . get_class($e) . ' - ' . $e->getMessage());
            return response()->json([
                'error' => 'Upload failed.',
            ], 500);
        }
    }

    /**
     * Hapus gambar berdasarkan relative path.
     * Dilindungi auth:sanctum.
     */
    public function delete(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = trim($request->input('path'), '/');

        // Cegah path traversal: hanya karakter aman diperbolehkan
        if (!preg_match('/^[a-zA-Z0-9_\-\/]+\.(webp|jpg|jpeg|png|gif|bmp)$/', $path)) {
            return response()->json(['error' => 'Invalid path format.'], 422);
        }

        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        $deleted = Storage::disk('public')->delete($path);

        return response()->json([
            'success' => $deleted,
            'path' => $path,
        ]);
    }
}
