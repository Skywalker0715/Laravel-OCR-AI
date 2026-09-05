<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mengompres dan memperkecil gambar struk secara in-place memakai GD bawaan PHP
 * (tanpa dependency tambahan). Struk hanya berisi teks sehingga resolusi lebar
 * 1000px sudah lebih dari cukup untuk dibaca Tesseract, namun ukuran file-nya
 * jauh lebih kecil dari foto asli dari kamera.
 *
 * Dipanggil sebelum OCR/AI parsing pada alur Create/Edit expense.
 */
class ImageCompressor
{
    /** Lebar target maksimal (px), tinggi mengikuti aspect ratio asli. */
    public const DEFAULT_MAX_WIDTH = 1000;

    /** Kualitas JPEG/WEBP (0-100), cukup untuk teks yang tetap terbaca. */
    public const DEFAULT_QUALITY = 75;

    /**
     * Resize (bila lebih lebar dari $maxWidth) lalu tulis ulang file yang sama
     * ke storage dengan kualitas lebih rendah. Ekstensi/format file dipertahankan.
     *
     * - JPEG  -> imagejpeg(dengan quality $quality)
     * - PNG   -> imagepng(dengan kompresi maksimal, lossless)
     * - WEBP  -> imagewebp(dengan quality $quality)
     * Format lain (mis. BMP/GIF) dilewati karena umumnya bukan untuk struk.
     *
     * @return bool true jika berhasil diproses, false jika gagal/gambar tidak layak.
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