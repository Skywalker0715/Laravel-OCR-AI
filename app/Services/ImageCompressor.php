<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kompres & perkecil gambar struk in-place memakai GD bawaan PHP (tanpa dependency).
 * Lebar 1000px sudah cukup untuk teks struk terbaca Tesseract, ukuran file jauh lebih kecil.
 * Dipanggil sebelum OCR/AI parsing pada alur Create/Edit expense.
 */
class ImageCompressor
{
    /** Lebar target maksimal (px), tinggi mengikuti aspect ratio asli. */
    public const DEFAULT_MAX_WIDTH = 1000;

    /** Kualitas JPEG/WEBP (0-100), cukup untuk teks yang tetap terbaca. */
    public const DEFAULT_QUALITY = 75;

    /**
     * Resize (bila lebih lebar dari $maxWidth) lalu tulis ulang file yang sama dengan
     * kualitas lebih rendah; JPEG/PNG/WEBP dipertahankan, format lain dilewati.
     * Mengembalikan false bila gagal diproses atau gambar tidak layak.
     */
    public function compressReceipt(string $path, int $maxWidth = self::DEFAULT_MAX_WIDTH, int $quality = self::DEFAULT_QUALITY): bool
    {
        if (! extension_loaded('gd')) {
            Log::warning('ImageCompressor: ekstensi GD tidak tersedia.');
            return false;
        }

        if (! is_file($path)) {
            return false;
        }

        try {
            $info = getimagesize($path);

            if ($info === false) {
                return false;
            }

            [$width, $height, $type] = $info;

            $source = match ($type) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($path),
                IMAGETYPE_PNG  => imagecreatefrompng($path),
                IMAGETYPE_WEBP => function_exists('imagewebp') ? imagecreatefromwebp($path) : false,
                default        => false,
            };

            if ($source === false) {
                return false;
            }

            // Hitung dimensi baru sesuai aspect ratio, hanya mengecilkan.
            $newWidth = $width;
            $newHeight = $height;

            if ($width > $maxWidth) {
                $ratio = $maxWidth / $width;
                $newWidth = (int) round($width * $ratio);
                $newHeight = (int) round($height * $ratio);
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            // Pertahankan kanal alpha untuk PNG agar tidak berubah jadi hitam.
            if ($type === IMAGETYPE_PNG) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
            }

            imagecopyresampled(
                $resized,
                $source,
                0, 0,
                0, 0,
                $newWidth, $newHeight,
                $width, $height,
            );

            $saved = false;

            // Tulis ke file temp dulu lalu pindahkan (rename) menimpa file asli.
            // Lebih aman daripada menulis langsung ke file yang dibaca GD —
            // menghindari lock / stale stat khususnya di Windows.
            $tempPath = $path.'.tmp'.bin2hex(random_bytes(4));
            $saved = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($resized, $tempPath, $quality),
                IMAGETYPE_PNG  => imagepng($resized, $tempPath, 9),
                IMAGETYPE_WEBP => imagewebp($resized, $tempPath, $quality),
            };

            if ($saved && ! rename($tempPath, $path)) {
                $saved = false;
            }

            if (! $saved && is_file($tempPath)) {
                @unlink($tempPath);
            }

            imagedestroy($source);
            imagedestroy($resized);

            return $saved;
        } catch (Throwable $th) {
            Log::warning('Gagal kompresi gambar struk', [
                'path' => $path,
                'error' => $th->getMessage(),
            ]);

            return false;
        }
    }
}