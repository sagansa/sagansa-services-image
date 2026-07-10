<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[Signature('img:migrate-legacy {source* : Direktori sumber (storage/app/public admin) yang akan disalin} {--dry : Tampilkan rencana tanpa menyalin}')]
#[Description('Salin file gambar lama dari storage admin ke disk public img service, mempertahankan relative path.')]
class MigrateLegacyImages extends Command
{
    /**
     * Default direktori yang biasanya berisi gambar di admin storage.
     */
    private const DEFAULT_DIRS = ['images', 'apks'];

    /**
     * Ekstensi file gambar yang dipertimbangkan (case-insensitive).
     */
    private const IMAGE_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'heic', 'heif'];

    /**
     * Ekstensi tambahan yang diizinkan (mis. APK).
     */
    private const EXTRA_EXTENSIONS = ['apk'];

    public function handle(): int
    {
        $sources = $this->argument('source');
        $dry = (bool) $this->option('dry');
        $disk = Storage::disk('public');
        $diskRoot = $disk->path('');

        $allowed = array_merge(self::IMAGE_EXTENSIONS, self::EXTRA_EXTENSIONS);

        $totalCopied = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($sources as $source) {
            $realSource = realpath($source);

            if (!$realSource || !is_dir($realSource)) {
                $this->error("Sumber tidak ditemukan / bukan direktori: {$source}");

                return self::FAILURE;
            }

            // Tentukan subpath relatif terhadap storage/app/public jika memungkinkan,
            // agar path hasil sama persis dengan konvensi admin (mis. images/Product/x.webp).
            $relativeBase = $this->relativeToPublicStorage($realSource);

            $this->info("Memproses: {$realSource}");
            if ($relativeBase !== '') {
                $this->line("  Relative base: {$relativeBase}");
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realSource, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $dirCopied = 0;
            $dirSkipped = 0;
            $dirFailed = 0;

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    continue;
                }

                // Hitung relative path: gabungkan relativeBase + path di dalam source
                $inside = ltrim(substr($file->getPathname(), strlen($realSource)), DIRECTORY_SEPARATOR);
                $relative = $relativeBase !== '' ? "{$relativeBase}/{$inside}" : $inside;
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                $destPath = rtrim($diskRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                if ($disk->exists($relative)) {
                    $dirSkipped++;
                    continue;
                }

                if ($dry) {
                    $this->line("  [DRY] {$relative}");
                    $dirCopied++;
                    continue;
                }

                if (!is_dir(dirname($destPath))) {
                    @mkdir(dirname($destPath), 0775, true);
                }

                if (@copy($file->getPathname(), $destPath)) {
                    $dirCopied++;
                } else {
                    $this->error("  Gagal menyalin: {$relative}");
                    $dirFailed++;
                }
            }

            $this->info("  -> Disalin: {$dirCopied} | Dilewati (sudah ada): {$dirSkipped} | Gagal: {$dirFailed}");
            $totalCopied += $dirCopied;
            $totalSkipped += $dirSkipped;
            $totalFailed += $dirFailed;
        }

        $this->newLine();
        $this->info("Total disalin: {$totalCopied} | Dilewati: {$totalSkipped} | Gagal: {$totalFailed}");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Jika source berada di dalam storage/app/public, kembalikan relative path-nya.
     * Mis. /path/admin/storage/app/public/images -> "images".
     * Jika tidak cocok, kembalikan '' (pakai basename folder terakhir).
     */
    private function relativeToPublicStorage(string $realSource): string
    {
        $marker = DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public';
        $pos = strpos($realSource, $marker);

        if ($pos !== false) {
            $rel = substr($realSource, $pos + strlen($marker));
            $rel = ltrim($rel, DIRECTORY_SEPARATOR);

            return str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        }

        // Fallback: pakai basename folder terakhir sebagai root relatif
        $base = basename($realSource);

        // Hindari prefix berulang jika user sudah menyertakan nama folder top-level
        if (in_array($base, self::DEFAULT_DIRS, true)) {
            return $base;
        }

        return $base;
    }
}
