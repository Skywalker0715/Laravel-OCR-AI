<?php

namespace App\Support;

/**
 * Pemotong isi log untuk data yang isinya TIDAK perlu (dan tidak boleh)
 * disimpan utuh: teks OCR struk, respons mentah Cohere, isi hasil parsing,
 * dan pertanyaan pengguna.
 *
 * Latar belakang (TASK 7 — hardening logging):
 *  - Isi struk bisa memuat nama toko, alamat, nomor telepon, dan nominal
 *    transaksi milik pengguna. Menulisnya utuh ke `storage/logs/laravel.log`
 *    berarti data pribadi user tersimpan permanen di file log — dan file log
 *    tersebut sering ikut tercopy saat pembeli mengirim log untuk debug.
 *  - Respons mentah Cohere berisi isi struk yang dikembalikan model (bisa
 *    mentranskripsikan ulang isi struk), jadi perlakuan yang sama berlaku.
 *  - Pertanyaan "Tanya AI" adalah input bebas user; isinya bisa memuat data
 *    sensitif dan tidak pernah perlu disimpan utuh untuk keperluan debugging.
 *
 * Aturan main: log level `info` hanya untuk event OPERASIONAL (job mulai/
 * selesai, gagal API, guard total) yang TIDAK memuat isi dokumen. Isi dokumen
 * hanya lewat `Log::debug()` dengan potongan singkat — default `.env.example`
 * memakai `LOG_LEVEL=info`, jadi isi dokumen otomatis tidak tertulis saat
 * aplikasi dijalankan di production, namun tetap bisa diaktifkan developer
 * lokal dengan `LOG_LEVEL=debug` ketika menelusuri masalah parsing.
 *
 * Kelas ini statis & tanpa dependency agar mudah diuji terpisah
 * (lihat tests/Unit/LogSanitizerTest.php).
 */
final class LogSanitizer
{
    /**
     * Batas panjang potongan (karakter) — cukup untuk melihat apakah OCR
     * berhasil membaca header struk tanpa menulis seluruh struk ke log.
     */
    public const EXCERPT_LIMIT = 200;

    /**
     * Potong nilai teks menjadi cuplikan maksimal $limit karakter.
     *
     * Baris baru dipipihkan menjadi spasi (teks struk multi-baris jadi jauh
     * lebih ringkas & enak dibaca di satu baris log), lalu dipotong. Nilai
     * yang lebih pendek dari batas dikembalikan apa adanya supaya log tetap
     * berguna tanpa noise "… [dipotong]" pada kasus kecil.
     */
    public static function excerpt(?string $value, int $limit = self::EXCERPT_LIMIT): string
    {
        $flattened = self::flattenWhitespace((string) $value);

        if ($flattened === '') {
            return '(kosong)';
        }

        if (mb_strlen($flattened) <= $limit) {
            return $flattened;
        }

        return mb_substr($flattened, 0, $limit).'… [dipotong]';
    }

    /**
     * Potong nilai yang bentuknya JSON (hasil parsing / respons API).
     *
     * String input dipakai langsung agar tidak encoding dua kali; array &
     * objek di-encode dulu. `JSON_INVALID_UTF8_SUBSTITUTE` penting di sini:
     * teks hasil OCR sering memuat byte non-UTF-8, dan tanpa flag itu
     * json_encode() mengembalikan false sehingga cuplikan hilang seluruhnya.
     */
    public static function jsonExcerpt(mixed $value, int $limit = self::EXCERPT_LIMIT): string
    {
        if (is_string($value)) {
            return self::excerpt($value, $limit);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return self::excerpt($encoded === false ? '[tidak dapat di-encode]' : $encoded, $limit);
    }

    /**
     * Rapatkan seluruh baris baru/tab menjadi satu spasi. Fallback ke regex
     * tanpa modifier `u` bila input bukan UTF-8 valid (preg_replace mengembalikan
     * null pada pola `u` kalau byte-nya rusak — hal yang sangat mungkin terjadi
     * pada teks OCR mentah).
     */
    private static function flattenWhitespace(string $value): string
    {
        $flattened = preg_replace('/\s+/u', ' ', $value);

        if ($flattened === null) {
            $flattened = preg_replace('/\s+/', ' ', $value);
        }

        return trim((string) $flattened);
    }
}