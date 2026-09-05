<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;
use thiagoalessio\TesseractOCR\UnsuccessfulCommandException;
use Throwable;

/**
 * Ekstraksi teks dari foto struk menggunakan Tesseract OCR.
 *
 * OCR dijalankan dalam dua pass yang saling melengkapi:
 *
 * 1. Pass preprocessing — gambar di-upscale (bila resolusinya kecil),
 *    di-grayscale, dan ditingkatkan contrast-nya via GD bawaan PHP (tanpa
 *    dependency tambahan). Tesseract membaca huruf kecil jauh lebih akurat
 *    pada resolusi lebih tinggi, sehingga nama toko/barang lebih benar.
 * 2. Pass native — gambar asli tanpa diubah, dibaca dengan PSM otomatis (3).
 *    Tesseract paling andal mengenali ANGKA pada resolusi asli foto; hasil
 *    pass ini dipakai untuk mengoreksi baris amount (total, tunai, kembalian,
 *    dan sejenisnya) di hasil pass preprocessing.
 *
 * Setiap tahap punya fallback: bila preprocessing gagal (GD tidak tersedia,
 * format tidak didukung, error apapun) OCR tetap berjalan pada gambar asli,
 * dan bila pass native gagal, hasil pass preprocessing tetap dipakai.
 */
class OCRService
{
    /**
     * PSM (Page Segmentation Mode) untuk pass preprocessing. PSM 6 —
     * "Assume a single uniform block of text" — umumnya paling cocok untuk
     * struk yang teksnya padat satu kolom.
     */
    public const DEFAULT_PSM = 6;

    /**
     * PSM untuk pass native (gambar asli). Nilai 3 = auto page segmentation,
     * paling andal mengenali blok teks bergaya/berukuran campuran (mis. baris
     * total yang dicetak lebih tebal) tempat angka-angka penting berada.
     */
    private const NATIVE_PSM = 3;

    /** Lebar minimum (px) agar tinggi huruf cukup besar untuk dibaca Tesseract. */
    private const MIN_OCR_WIDTH = 1000;

    /** Lebar target (px) saat meng-upscale gambar beresolusi kecil. */
    private const TARGET_WIDTH = 1500;

    /**
     * Batas maksimal faktor upscale. Hasil uji menunjukkan upscale 2x paling
     * seimbang: di atas itu artefak kompresi JPEG ikut membesar sehingga
     * angka justru lebih sering salah dibaca dan proses makin lambat.
     */
    private const MAX_UPSCALE_FACTOR = 2.0;

    /**
     * Level contrast untuk IMG_FILTER_CONTRAST. Keanehan API GD: nilai
     * NEGATIF justru menaikkan kontras. Jangan berlebihan — kontras terlalu
     * kuat menggumpalkan huruf pada JPEG terkompresi.
     */
    private const CONTRAST_LEVEL = -8;

    /**
     * Kernel sharpen 3x3 standar (pusat 5, tetangga -1). DINONAKTIFKAN secara
     * default karena uji ablation pada foto struk JPEG terkompresi menunjukkan
     * sharpen memunculkan halo di tepi huruf yang membuat Tesseract salah
     * membaca (akurasi turun drastis). Tetap tersedia (aktifkan via
     * constructor) untuk kasus foto yang blur/soft-focus.
     */
    private const SHARPEN_MATRIX = [[0.0, -1.0, 0.0], [-1.0, 5.0, -1.0], [0.0, -1.0, 0.0]];

    /**
     * Skor kemiripan minimum (persen, dari similar_text) antara label baris
     * hasil pass preprocessing dan pass native agar dianggap baris yang sama
     * saat mengoreksi nilai amount.
     */
    private const LABEL_SIMILARITY_THRESHOLD = 75.0;

    public function __construct(
        private readonly int $psm = self::DEFAULT_PSM,
        private readonly bool $sharpen = false,
    ) {}

    /**
     * Membaca teks dari gambar struk.
     *
     * @param  string  $path  Path absolut ke file gambar struk.
     * @return string Teks mentah hasil OCR. Berupa gabungan dua pass bila
     *                gambar sempat di-upscale, atau satu pass bila tidak.
     */
    public function extractTextFromImage(string $path): string
    {
        [$processedPath, $upscaled] = $this->preprocessImage($path);

        try {
            $primary = $this->runTesseract($processedPath, $this->psm);
        } finally {
            // Bersihkan file hasil preprocessing agar direktori temp tidak menumpuk.
            if ($processedPath !== $path && is_file($processedPath)) {
                @unlink($processedPath);
            }
        }

        // Gambar yang tidak di-upscale berarti sudah beresolusi cukup: satu
        // pass saja sudah optimal, pass native tidak memberi info tambahan.
        if (! $upscaled) {
            return $primary;
        }

        $native = $this->runNativePass($path);

        if ($native === null) {
            return $primary;
        }

        return $this->mergeAmountLines($primary, $native);
    }

    /**
     * Pass kedua pada gambar asli (tanpa preprocessing). Bersifat best-effort:
     * bila gagal, kembalikan null dan pemanggil memakai hasil pass pertama.
     */
    private function runNativePass(string $path): ?string
    {
        try {
            return $this->runTesseract($path, self::NATIVE_PSM);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Menjalankan Tesseract pada satu gambar, dengan satu kali percobaan ulang
     * bila proses gagal secara transient (di Windows, Tesseract kadang gagal
     * membaca file temp yang baru dibuat — mis. sedang dipindai antivirus).
     */
    private function runTesseract(string $imagePath, int $psm): string
    {
        try {
            return $this->executeTesseract($imagePath, $psm);
        } catch (UnsuccessfulCommandException) {
            return $this->executeTesseract($imagePath, $psm);
        }
    }

    private function executeTesseract(string $imagePath, int $psm): string
    {
        return (new TesseractOCR(image: $imagePath))
            ->lang('ind', 'eng')
            ->psm($psm)
            ->run();
    }

    /**
     * Menyiapkan versi gambar yang lebih mudah dibaca Tesseract:
     * upscale (bila kecil) → grayscale → contrast → sharpen (opsional).
     *
     * @param  string  $path  Path absolut ke file gambar sumber.
     * @return array{0: string, 1: bool} Path gambar yang siap dibaca OCR
     *                 (file PNG sementara hasil preprocessing, atau path asli
     *                 bila preprocessing tidak bisa dilakukan) dan flag apakah
     *                 gambar benar-benar di-upscale.
     */
    public function preprocessImage(string $path): array
    {
        if (! extension_loaded('gd')) {
            return [$path, false];
        }

        try {
            $info = @getimagesize($path);

            if ($info === false) {
                return [$path, false];
            }

            [$width, $height, $type] = $info;

            $image = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                default => false,
            };

            if ($image === false) {
                return [$path, false];
            }

            $upscaled = false;

            // 1. Upscale gambar kecil: Tesseract jauh lebih akurat ketika
            //    tinggi huruf cukup besar (ideal ~300 DPI). Faktor upscale
            //    dibatasi MAX_UPSCALE_FACTOR agar memori & waktu tetap wajar.
            $scale = 1.0;

            if ($width > 0 && $width < self::MIN_OCR_WIDTH) {
                $scale = min(self::TARGET_WIDTH / $width, self::MAX_UPSCALE_FACTOR);
            }

            if ($scale > 1.0) {
                $newWidth = (int) round($width * $scale);
                $newHeight = (int) round($height * $scale);

                $upscaledImage = imagecreatetruecolor($newWidth, $newHeight);

                // Isi latar putih supaya area transparan (PNG) tidak terbaca
                // sebagai blok gelap oleh Tesseract.
                $white = imagecolorallocate($upscaledImage, 255, 255, 255);
                imagefill($upscaledImage, 0, 0, $white);

                imagecopyresampled(
                    $upscaledImage,
                    $image,
                    0, 0, 0, 0,
                    $newWidth, $newHeight,
                    $width, $height,
                );

                imagedestroy($image);
                $image = $upscaledImage;
                $upscaled = true;
            }

            // 2. Grayscale: struk tidak membutuhkan warna, Tesseract lebih
            //    stabil membaca citra abu-abu.
            imagefilter($image, IMG_FILTER_GRAYSCALE);

            // 3. Contrast: tinta makin pekat, latar makin bersih.
            imagefilter($image, IMG_FILTER_CONTRAST, self::CONTRAST_LEVEL);

            // 4. Sharpen: default nonaktif (lihat SHARPEN_MATRIX), aktifkan
            //    hanya untuk foto yang memang blur/soft-focus.
            if ($this->sharpen) {
                imageconvolution($image, self::SHARPEN_MATRIX, 1.0, 0.0);
            }

            // Simpan sebagai PNG lossless (tanpa artefak JPEG) di temp OS.
            $tempPath = $this->createTempPngPath();

            if ($tempPath === null) {
                imagedestroy($image);

                return [$path, false];
            }

            $saved = @imagepng($image, $tempPath, 6);
            imagedestroy($image);

            if (! $saved) {
                @unlink($tempPath);

                return [$path, false];
            }

            return [$tempPath, $upscaled];
        } catch (Throwable) {
            // Jangan biarkan preprocessing menggagalkan OCR — pakai gambar asli.
            return [$path, false];
        }
    }

    /**
     * Menggabungkan hasil dua pass: baris angka penting (total, tunai,
     * kembalian, hemat, dan sejenisnya) dari pass native lebih andal, sehingga
     * dipakai untuk mengoreksi baris yang sama di hasil pass preprocessing.
     *
     * @param  string  $primary  Teks hasil pass preprocessing (upscaled).
     * @param  string  $native   Teks hasil pass native (gambar asli).
     */
    private function mergeAmountLines(string $primary, string $native): string
    {
        $nativeLines = [];

        foreach (preg_split('/\R/', $native) ?: [] as $line) {
            $line = trim($line);

            if (self::lineHasSignificantAmount($line)) {
                $nativeLines[] = $line;
            }
        }

        if ($nativeLines === []) {
            return $primary;
        }

        $corrected = 0;
        $primaryLines = [];

        foreach (preg_split('/\R/', $primary) ?: [] as $line) {
            $primaryLines[] = self::tryCorrectAmountLine($line, $nativeLines, $corrected);
        }

        // Tidak ada baris yang dikoreksi: hasil kedua pass identik secara
        // praktis, kembalikan pass preprocessing apa adanya.
        // Baris tanggal yang hanya terbaca di pass native tetap dipertahankan
        // agar tanggal struk tidak hilang dari hasil final.
        $primaryText = implode("\n", $primaryLines);
        $dateAddedFromNative = false;
        foreach ($nativeLines as $nativeLine) {
            if (! self::lineContainsDate($nativeLine)) {
                continue;
            }
            if (strpos($primaryText, $nativeLine) !== false) {
                continue;
            }
            $primaryLines[] = $nativeLine;
            $dateAddedFromNative = true;
        }

        if ($corrected === 0 && ! $dateAddedFromNative) {
            return $primary;
        }

        return implode("\n", $primaryLines);
    }

    /**
     * Mencoba mengganti baris dengan versi pass native bila labelnya mirip
     * dan kedua pass membaca angka yang berbeda. Tidak melakukan parsing
     * format struk apapun — murni pembandingan baris antar dua pembacaan OCR.
     *
     * @param  array<int, string>  $nativeLines
     */
    private static function tryCorrectAmountLine(string $line, array $nativeLines, int &$corrected): string
    {
        if (! self::lineHasSignificantAmount($line)) {
            return $line;
        }

        $label = self::lineLabelKey($line);
        $best = null;
        $bestScore = 0.0;

        foreach ($nativeLines as $candidate) {
            if ($candidate === $line) {
                return $line;
            }

            $score = 0.0;
            similar_text($label, self::lineLabelKey($candidate), $score);

            if ($score >= self::LABEL_SIMILARITY_THRESHOLD && $score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        if ($best === null) {
            return $line;
        }

        // Baris primary yang memuat tanggal TIDAK boleh digantikan kandidat
        // native yang tanggalnya hilang (mis. "No. Struk : 211 10.01.2023-
        // 10:11:07" diganti "No. Struk : 211").
        if (self::lineContainsDate($line) && ! self::lineContainsDate($best)) {
            return $line;
        }

        $corrected++;

        return $best;
    }

    /**
     * Baris ber-signifikan = memuat angka dengan minimal 3 digit
     * (mis. "98,700", "1.305.500") setelah pemisah ribuan diabaikan.
     */
    private static function lineHasSignificantAmount(string $line): bool
    {
        $digitsOnly = preg_replace('/[.,\s]/', '', $line) ?? '';

        return preg_match('/\d{3}/', $digitsOnly) === 1;
    }

    /**
     * Kunci perbandingan baris: bagian non-angka dari baris (labelnya),
     * dinormalisasi. Baris "TOTAL BELANJA : 98,760" dan "TOTAL BELANJA : 98,700"
     * menghasilkan kunci yang sama sehingga nilai angkanya bisa disandingkan.
     */
    private static function lineLabelKey(string $line): string
    {
        $letters = trim((string) preg_replace('/[^[:alpha:]]+/', ' ', $line));

        return mb_strtolower($letters);
    }

    /**
     * True bila baris memuat tanggal yang valid secara kalender
     * (DD.MM.YYYY / DD-MM-YYYY / YYYY-MM-DD, boleh menempel waktu seperti
     * DD.MM.YYYY-HH:MM:SS). Dipakai saat merge dua pass OCR agar baris
     * tanggal tidak sengaja dibuang/diganti versi pass lain yang kehilangan
     * tanggalnya.
     */
    private static function lineContainsDate(string $line): bool
    {
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $line, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        }

        // Hanya kandidat tanggal yang VALID kalender (mis. 10.01.2023), supaya
        // nominal ribuan seperti "50.000.000" / "24,000,000" tidak dianggap.
        if (preg_match_all('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})\b/', $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $day = (int) $mm[1];
                $month = (int) $mm[2];
                $year = (int) $mm[3];
                if ($year < 100) {
                    $year += 2000;
                }
                if (checkdate($month, $day, $year)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Membuat path file sementara berekstensi .png. Ekstensi penting karena
     * Leptonica (library pencitraan Tesseract) lebih andal mengenali format
     * gambar dari ekstensi file, sedangkan tempnam() menghasilkan nama tanpa
     * ekstensi.
     */
    private function createTempPngPath(): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'ocr_');

        if ($base === false) {
            return null;
        }

        $withExtension = $base.'.png';

        if (! @rename($base, $withExtension)) {
            @unlink($base);

            return null;
        }

        return $withExtension;
    }
}
