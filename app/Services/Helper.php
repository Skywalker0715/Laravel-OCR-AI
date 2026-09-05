<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Utilitas parsing teks struk: membersihkan respons AI, menormalkan nominal
 * & tanggal, serta menemukan total paling meyakinkan dari teks OCR.
 *
 * Dipakai bersama oleh AIParserService (jalur AI maupun fallback regex) dan
 * AIParserJob.
 */
class Helper
{
    /**
     * Ekstrak payload JSON dari respons mentah Cohere (yang sering diselingi
     * teks pembuka/penutup) lalu dekode menjadi array. Null bila gagal —
     * pemanggil akan jatuh ke parser fallback.
     */
    public function cleanCohereResponse(string $responseText): ?array
    {
        // Hapus teks di awal sebelum karakter { atau [.
        $cleaned = trim(string: preg_replace(pattern: '/^[^{[]+/', replacement: '', subject: $responseText));

        // Hapus trailing teks setelah JSON ditutup.
        $closingPos = strrpos(haystack: $cleaned, needle: '}');

        if ($closingPos !== false) {
            $cleaned = substr(string: $cleaned, offset: 0, length: $closingPos + 1);
        }

        try {
            return json_decode(json: $cleaned, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning(message: 'Gagal decode JSON AI: ' . $e->getMessage(), context: [
                'response' => $responseText,
                'cleaned' => $cleaned,
            ]);

            return null;
        }
    }

    /**
     * Ubah string nominal (format Indonesia maupun generik) menjadi float.
     *
     * Tujuan: parser fallback TIDAK boleh menghitung nominal salah (mis.
     * "Rp30.000" menjadi 3000) hanya karena salah tahu pemisah ribuan vs
     * desimal. Aturan yang dipakai (cukup toleran untuk OCR struk):
     *  - "Rp", "IDR", spasi dihapus.
     *  - Pemisah kelompok-3 digit (mis. "30.000", "25.000", "10,900")
     *    diperlakukan sebagai pemisah ribuan, bukan desimal.
     *  - Pemisah yang diikuti 1-2 digit dan bukan pola kelompok-3 diperlakukan
     *    sebagai desimal ("1.5", "15,75").
     *  - Tanda minus/"(...)" menandakan nilai negatif (baris retur/diskon).
     *
     * @param  mixed  $value  String nominal atau nilai lain yang bisa di-cast.
     */
    public function parseMoneyNumber(mixed $value): float
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return 0.0;
        }

        $value = str_ireplace(['Rp', 'idr', ' '], '', $value);

        // Deteksi negatif (baris retur/diskon sering berupa "-1" / "(200)").
        $negative = str_contains($value, '-') || preg_match('/^\(.*\)$/', $value) === 1;
        $digits = str_replace(['(', ')', '-'], '', preg_replace('/[^\d.,-]/', '', $value));

        if ($digits === '') {
            return 0.0;
        }

        $hasComma = str_contains($digits, ',');
        $hasDot = str_contains($digits, '.');

        $decimalSep = null;
        $thousandSep = null;

        if ($hasComma && $hasDot) {
            // Dua jenis pemisah: yang muncul paling belakang kemungkinan desimal.
            $posComma = strrpos($digits, ',');
            $posDot = strrpos($digits, '.');

            if ($posDot > $posComma) {
                $decimalSep = '.';
                $thousandSep = ',';
            } else {
                $decimalSep = ',';
                $thousandSep = '.';
            }
        } elseif ($hasComma) {
            if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $digits) === 1) {
                $thousandSep = ','; // "10,900" / "9,400" = ribuan
            } else {
                $decimalSep = ',';  // "15,75" = desimal
            }
        } elseif ($hasDot) {
            if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $digits) === 1) {
                $thousandSep = '.'; // "30.000" / "25.000" = ribuan
            } else {
                $decimalSep = '.';  // "1.5" = desimal
            }
        }

        if ($thousandSep !== null) {
            $digits = str_replace($thousandSep, '', $digits);
        }

        if ($decimalSep !== null) {
            [$int, $dec] = array_pad(explode($decimalSep, $digits, 2), 2, '');

            // Inti & desimal disatukan menjadi float (jangan pakai operator '.'
            // langsung karena itu string-concat; bug lama membuat return bertipe
            // string → TypeError saat nilai desimal muncul, mis. "15,75").
            $result = (float) (($int === '' ? '0' : $int).'.'.($dec === '' ? '0' : $dec));
        } else {
            $result = (float) $digits;
        }

        return $negative ? -($result) : $result;
    }

    /**
     * Ambil angka (nominal) terbesar dari sebuah teks.
     *
     * Pemisah ribuan diberi toleransi spasi opsional setelah koma/titik karena
     * OCR struk sering menyisipkan spasi pada nominal, mis. "90, 700" (harusnya
     * 90,700 = 90.700 rupiah). Tanpa toleransi ini, teks tersebut terpecah jadi
     * "90" dan "700" sehingga total salah terambil sebesar 700.
     */
    public function extractLargestNumber(string $text): float
    {
        preg_match_all('/[+-]?\d{1,3}(?:[.,] ?\d{3})*(?:[.,]\d{1,2})?|\d+[.,]\d+/', $text, $matches);

        $largest = 0.0;
        foreach ($matches[0] ?? [] as $token) {
            $value = $this->parseMoneyNumber($token);
            if ($value > $largest) {
                $largest = $value;
            }
        }

        return $largest;
    }

    /**
     * Cari nominal total paling meyakinkan dari daftar baris teks struk.
     *
     * Prioritas:
     *  1. Baris berisi "belanja"/"grand total" → nominal terbesar di baris itu.
     *  2. Baris berisi "total" (tapi bukan disc/item) → nominal terbesar.
     *  3. Jumlahkan subtotal item yang berhasil dikenali.
     *  4. Angka terbesar di seluruh teks (sangat longgar, hanya agar ada nilai).
     *
     * Baris yang jelas bukan nominal (tanggal, NPWP/NPUP, telepon) ikut
     * dilompati agar angka kebetulan (mis. nomor NPUP) tidak terambil.
     */
    public function extractBestTotal(array $lines, array $items = []): float
    {
        $normalized = array_map('strtolower', $lines);

        // 1. Baris total belanja / grand total.
        foreach ($normalized as $i => $line) {
            if (str_contains($line, 'total') && (str_contains($line, 'belan') || str_contains($line, 'grand'))) {
                if (! $this->isIdentifierLine($line)) {
                    $found = $this->extractLargestNumber($lines[$i]);
                    if ($found > 0) {
                        return $found;
                    }
                }
            }
        }

        // 2. Baris "total" lain (kecuali disc/item/qty) — ambil yang terbesar.
        $bestFromTotal = 0.0;
        foreach ($normalized as $i => $line) {
            if (! str_contains($line, 'total')) {
                continue;
            }
            if (str_contains($line, 'disc') || str_contains($line, 'item') || str_contains($line, 'qty')) {
                continue;
            }
            if ($this->isIdentifierLine($line)) {
                continue;
            }
            $bestFromTotal = max($bestFromTotal, $this->extractLargestNumber($lines[$i]));
        }
        if ($bestFromTotal > 0) {
            return $bestFromTotal;
        }

        // 3. Jumlah subtotal item.
        $sumItems = 0.0;
        foreach ($items as $item) {
            $sumItems += (float) ($item['subtotal'] ?? 0);
        }
        if ($sumItems > 0) {
            return $sumItems;
        }

        // 4. Angka terbesar apa pun (sangat longgar).
        return $this->extractLargestNumber(implode("\n", $lines));
    }

    /**
     * True jika baris terlihat sebagai baris identifikasi (tanggal, NPWP/NPUP,
     * nomor telepon) dan bukan baris nominal belanja.
     */
    private function isIdentifierLine(string $lowerLine): bool
    {
        return (bool) preg_match(
            '/(\d{4}[-_\/]?\d{2}[-_\/]?\d{2}|npwp|npup|telp|telepon|n\.?p\.?w?p?)/i',
            $lowerLine
        );
    }

    /**
     * Parse tanggal dari format umum struk Indonesia menjadi YYYY-MM-DD.
     *
     * Menerima YYYY-MM-DD maupun dd-mm-yyyy / dd/mm/yyyy / dd-mm-yy. Karena
     * struk Indonesia selalu memakai dd-mm-yyyy, format ambigu (dua angka <=12)
     * diselesaikan sebagai tanggal-hari-bulan.
     */
    public function parseFlexibleDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // YYYY-MM-DD (standar / hasil AI) — langsung.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
        }

        // dd/mm/yyyy, dd-mm-yyyy, dd.mm.yyyy (atau yy).
        if (preg_match('/^(\d{1,2})[\-\/\.](\d{1,2})[\-\/\.](\d{2,4})$/', $value, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        // BUG 5: dd-Mon-yyyy ("05-Jan-2021", "11 Apr 2012", "05-Jan-21") —
        // nama bulan Indonesia/Inggris, dipisah dash/slash/titik/spasi.
        // Sebelumnya hanya format angka yang didukung sehingga struk tagihan
        // (PLN/bank) tersimpan tanpa tanggal padahal tanggalnya tercetak jelas.
        if (preg_match('/^(\d{1,2})[\-\/\. ]+([A-Za-z]{3,9})[\-\/\. ]+(\d{2,4})$/', $value, $m)) {
            $month = $this->indonesianMonthNumber($m[2]);
            $day = (int) $m[1];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }

            if ($month === null || ! checkdate($month, $day, $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /**
     * Nomor bulan (1-12) dari nama bulan Indonesia/Inggris — 3 huruf pertama
     * sudah cukup unik (jan, feb, mar, apr, mei, jun, jul, agu/agt/aug, sep,
     * okt/oct, nov, des/dec).
     */
    private function indonesianMonthNumber(string $name): ?int
    {
        $prefix = strtolower(substr(trim($name), 0, 3));

        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5,
            'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8, 'aug' => 8,
            'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11,
            'des' => 12, 'dec' => 12,
        ];

        return $months[$prefix] ?? null;
    }
}
