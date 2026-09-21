<?php

namespace App\Services\Parsing;

use App\Services\Helper;

/**
 * Mendeteksi kemungkinan item "halusinasi" hasil parsing AI (Cohere): nama item
 * yang dibuat-buat atau dipasang ke teks OCR yang sebenarnya bukan baris item.
 *
 * Dua sinyal dipakai bersama (item dianggap "halusinasi-like" bila salah satu
 * terpenuhi):
 *  1. Sinyal "nama tak ditemukan" — nama item tidak muncul sama sekali
 *     (fuzzy, case-insensitive) di dalam teks OCR asli (kolom note).
 *  2. Sinyal "nama yatim" (orphan) — nama item muncul di OCR, tetapi TIDAK ada
 *     baris di sekitar kemunculannya (jendela ±CO_LOCATION_WINDOW) yang memuat
 *     nominal (price/subtotal) item tersebut. Pola ini menangkap kasus AI yang
 *     mengubah baris header/keterangan menjadi item palsu: struk parkir SECURE
 *     PARK memuat "TIKET PARKIR MOBIL" + baris tarif "Tarif Jam ke-1 Rp 5.000",
 *     lalu AI "membuat" item "Parkir Mobil" (nama ketemu sebagai sub-string
 *     header, tapi tidak pernah berdampingan dengan harganya).
 *
 * Pemakai: AIParserJob::explicitTotalOverrideJustified — bila mayoritas item
 * kemungkinan halusinasi DAN ada baris total FINAL eksplisit (TOTAL BAYAR/dst.),
 * baris eksplisit itu menang atas nilai AI (walau AI "konsisten" dengan
 * penjumlahan item halusinasi). Kasus BNI (item sah: "TAG PLN"/"ADMIN BANK"
 * muncul bersama nominalnya, termasuk toleransi digit-nyelip "11600"→"1600")
 * TIDAK terdeteksi sebagai halusinasi, sehingga perilaku lama dipertahankan.
 */
final class ItemHallucinationDetector
{
    /** Mayoritas item halusinasi-like agar struk ditandai ("SEBAGIAN BESAR ATAU SEMUA"). */
    private const HALLUCINATED_SHARE = 0.5;

    /** Ambang similar_text per-baris (%) untuk "nama ditemukan" saat containment gagal. */
    private const FUZZY_LINE_SIMILARITY = 70.0;

    /** Panjang minimal nama agar fallback fuzzy per-baris dianggap bermakna. */
    private const FUZZY_MIN_NAME_LENGTH = 4;

    /** Lebar jendela baris (±) di sekitar baris berisi nama untuk pencarian nominal. */
    private const CO_LOCATION_WINDOW = 2;

    private Helper $helper;

    public function __construct(?Helper $helper = null)
    {
        $this->helper = $helper ?? new Helper;
    }

    /**
     * True bila SEBAGIAN BESAR (mayoritas, > HALLUCINATED_SHARE) item yang valid
     * kemungkinan halusinasi (nama tak ditemukan / nama yatim). False bila item
     * kosong atau mayoritas item terbukti "grounded" (namanya berdampingan
     * dengan nominal di OCR).
     *
     * @param  array<int, mixed>  $items  Item hasil parsing (AI/fallback).
     * @param  array<int, string>  $lines  Baris teks OCR yang sudah di-trim.
     */
    public function itemsPossiblyHallucinated(array $items, array $lines): bool
    {
        $valid = array_values(array_filter(
            $items,
            fn ($item): bool => is_array($item) && ! empty($item['name'])
        ));

        if ($valid === []) {
            return false;
        }

        $hallucinated = 0;
        foreach ($valid as $item) {
            if (! $this->itemIsGrounded($item, $lines)) {
                $hallucinated++;
            }
        }

        return ($hallucinated / count($valid)) > self::HALLUCINATED_SHARE;
    }

    /**
     * True bila item "grounded" di teks OCR: nama ditemukan (fuzzy) DAN ada
     * baris di sekitar kemunculannya yang memuat nominal price/subtotal item
     * (dengan toleransi selisih kecil maupun digit-nyelip OCR).
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $lines
     */
    
    private function itemIsGrounded(array $item, array $lines): bool
    {
        $name = (string) ($item['name'] ?? '');
        if ($name === '') {
            return true; // item tanpa nama tidak dinilai (bukan bukti halusinasi)
        }

        $nameLines = $this->findNameLines($name, $lines);
        if ($nameLines === []) {
            return false; // Sinyal 1: nama tidak ditemukan sama sekali di OCR.
        }

        $price = (float) ($item['price'] ?? 0);
        $subtotal = (float) ($item['subtotal'] ?? 0);

        foreach ($nameLines as $idx) {
            $start = max(0, $idx - self::CO_LOCATION_WINDOW);
            $end = min(count($lines) - 1, $idx + self::CO_LOCATION_WINDOW);

            for ($i = $start; $i <= $end; $i++) {
                foreach ($this->numbersInLine($lines[$i]) as $number) {
                    if ($this->moneyMatches($number, $price) || $this->moneyMatches($number, $subtotal)) {
                        return true; // nominal item berdampingan dengan namanya → sah.
                    }
                }
            }
        }

        return false; // Sinyal 2: nama yatim (orphan) — nama ketemu, nominal tidak.
    }

    /**
     * Indeks baris-baris yang memuat nama item (case-insensitive). Pencarian
     * memakai containment sederhana setelah normalisasi (hapus non-alfanumerik),
     * dengan fallback similar_text per-baris untuk menoleransi noise OCR ringan.
     *
     * @return array<int, int>
     */
    private function findNameLines(string $name, array $lines): array
    {
        $normalizedName = $this->normalize($name);
        if ($normalizedName === '') {
            return [];
        }

        $found = [];
        foreach ($lines as $i => $line) {
            $normalizedLine = $this->normalize($line);
            if ($normalizedLine === '') {
                continue;
            }

            if (mb_strpos($normalizedLine, $normalizedName) !== false) {
                $found[] = $i;
                continue;
            }

            if (
                mb_strlen($normalizedName) >= self::FUZZY_MIN_NAME_LENGTH
                && $this->lineSimilarity($normalizedName, $normalizedLine) >= self::FUZZY_LINE_SIMILARITY
            ) {
                $found[] = $i;
            }
        }

        return $found;
    }

    /**
     * Semua nominal (absolut) yang terekstrak dari satu baris. Pola token sama
     * dengan Helper::extractLargestNumber agar konsisten (mis. "90, 700" tetap
     * terbaca 90.700).
     *
     * @return array<int, float>
     */
    private function numbersInLine(string $line): array
    {
        preg_match_all('/[+-]?\d{1,3}(?:[.,] ?\d{3})*(?:[.,]\d{1,2})?|\d+[.,]\d+/', $line, $matches);

        $numbers = [];
        foreach ($matches[0] ?? [] as $token) {
            $value = $this->helper->parseMoneyNumber($token);
            if ($value != 0.0) { // abaikan nol/nomor kosong (bukan nominal harga)
                $numbers[] = abs($value);
            }
        }

        return $numbers;
    }

    /**
     * True bila dua nominal "cocok": sama persis, dalam toleransi selisih kecil
     * (1% / Rp 0,5 — noise pembulatan OCR), atau salah satunya adalah hasil
     * menghapus satu digit dari yang lain (digit-nyelip OCR, mis. "11600" vs
     * harga "1600" pada kasus BNI).
     */
    private function moneyMatches(float $a, float $b): bool
    {
        if ($b <= 0) {
            return false;
        }

        if (abs($a - $b) <= max(0.5, 0.01 * $b)) {
            return true;
        }

        $aInt = (string) (int) round($a);
        $bInt = (string) (int) round($b);

        if (in_array($bInt, $this->singleDigitDeletionVariants($aInt), true)) {
            return true;
        }

        if (in_array($aInt, $this->singleDigitDeletionVariants($bInt), true)) {
            return true;
        }

        return false;
    }

    /** Semua nilai unik hasil menghapus SATU digit dari token angka ("11600" → {"1600","1100","1160"}). */
    private function singleDigitDeletionVariants(string $digits): array
    {
        $variants = [];
        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $variant = substr($digits, 0, $i).substr($digits, $i + 1);
            if ($variant !== '' && ! in_array($variant, $variants, true)) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    /** Skor similar_text (0-100) antara dua string ternormalisasi. */
    private function lineSimilarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);

        return $percent;
    }

    /**
     * Normalisasi teks untuk pencocokan nama yang toleran: huruf kecil, buang
     * karakter non-alfanumerik (spasi dipertahankan), rapikan spasi ganda.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9\s]+/u', '', $value) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

