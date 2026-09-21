<?php

namespace App\Services\Parsing;

use App\Services\Helper;

/**
 * Menjelaskan selisih (gap) antara dua nominal struk memakai baris-baris
 * penyesuai (diskon, pajak/PPN, biaya admin, ongkir, dsb).
 *
 * Menggantikan pendekatan lama yang hanya mengenali SATU baris penyesuai:
 * kini gap bisa dijelaskan oleh KOMBINASI BEBERAPA baris sekaligus (mis.
 * "PPN 11% 10.725" + "Diskon Promo -5.000" menjelaskan total 103.225 dari
 * subtotal 97.500), dengan tanda plus/minus yang benar, memakai nilai
 * literal OCR maupun nilai hasil perhitungan persentase terhadap subtotal item.
 *
 * Kelas ini murni dan tanpa dependency berat — mudah diuji unit dan dipakai
 * dari AIParserJob (guard total & guard mismatch item).
 */
final class AdjustmentLinesExplainer
{
    /** Baris yang dikenali sebagai baris penyesuai (diskon/pajak/biaya). */
    private const ADJUSTMENT_PATTERN = '/disc|diskon|potong|hemat|rabat|retur|promo|biaya|admin|ong\s*kir|ongkos|service|charge|fee|pajak|tax|\bvat\b|ppn|pembulatan|dibulatkan|bulat/iu';

    /** Baris bernada diskon (kontribusi negatif ke total). */
    private const DISCOUNT_PATTERN = '/disc|diskon|potong|hemat|rabat|retur|promo/iu';

    /** Baris bernada pajak/biaya tetap (kontribusi positif ke total). */
    private const TAX_FEE_PATTERN = '/biaya|admin|ong\s*kir|ongkos|service|charge|fee|pajak|tax|\bvat\b|ppn/iu';

    /**
     * True bila gap (bisa negatif/positif) antara dua nominal struk dapat
     * dijelaskan oleh kombinasi baris-baris penyesuai pada teks OCR.
     *
     * Dua nominal yang dimaksud: "nilai awal = penjumlahan subtotal item" dan
     * "nilai akhir = total akhir struk (setelah diskon/pajak)". Gap = akhir - awal.
     * Misalnya gap +5.725 bisa dijelaskan oleh PPN +10.725 dan diskon -5.000.
     *
     * @param  float  $gap         Selisih bertanda: totalAkhir - jumlahSubtotalItem.
     * @param  float  $baseAmount  Subtotal item (dasar perhitungan persentase).
     * @param  array<int, string>  $lines      Baris teks OCR yang sudah di-trim.
     * @param  float  $tolerance   Toleransi residual (Guard Total: 0.01 = harus
     *                             persis; guard mismatch item: ambang residual noise).
     */
    public function isGapExplained(float $gap, float $baseAmount, array $lines, float $tolerance = 0.01): bool
    {
        if (abs($gap) <= 0.01) {
            return true;
        }

        $candidates = $this->extractContributionCandidates($lines, $baseAmount);

        if ($candidates === []) {
            return false;
        }

        return $this->canReachTarget($candidates, $gap, 0, max(0.5, $tolerance));
    }

    /**
     * Kumpulkan kandidat kontribusi (nilai bertanda) dari setiap baris penyesuai.
     * Sebuah baris bisa menghasilkan 0-2 kandidat: nilai literal OCR (dengan
     * arah tanda dari minus/kata kunci) dan nilai hasil perhitungan persentase
     * terhadap subtotal item (arah dari kata kunci diskon vs pajak).
     *
     * @param  array<int, string>  $lines
     * @return array<int, array<int, float>>  Daftar baris; tiap baris = kandidat nilai.
     */
    private function extractContributionCandidates(array $lines, float $baseAmount): array
    {
        $rows = [];

        foreach ($lines as $line) {
            if (preg_match(self::ADJUSTMENT_PATTERN, $line) !== 1) {
                continue;
            }

            $cands = [];

            // Kandidat 1: nilai literal pada baris. Helper::extractLargestNumber
            // membuang angka negatif (hanya mengembalikan nilai > 0), sehingga
            // dipakai ekstraksi "magnitude terbesar" yang mempertahankan tanda
            // token ("Diskon Promo -5.000" → -5000, bukan 0).
            $literal = $this->largestMagnitudeNumber($line);
            if ($literal != 0.0) {
                $cands[] = $this->signedLiteral($literal, $line);
            }

            // Kandidat 2: nilai persentase terhadap subtotal item
            // (mis. "PPN 11%" dari subtotal 97.500 = 10.725).
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $line, $m) === 1) {
                $percent = (float) str_replace(',', '.', $m[1]);
                $fromPercent = round($baseAmount * $percent / 100, 2);
                if ($fromPercent > 0) {
                    $cands[] = $this->signedPercent($fromPercent, $line);
                }
            }

            if ($cands !== []) {
                $rows[] = array_values(array_unique($cands, SORT_NUMERIC));
            }
        }

        return $rows;
    }

    /**
     * Tanda nilai literal: token sudah bisa membawa tanda sendiri ("-5.000").
     * Bila token positif tapi baris bernada diskon (tanpa pajak/biaya) →
     * kontribusi dianggap negatif ("Total Disc. 1.500" adalah pengurang).
     */
    private function signedLiteral(float $value, string $line): float
    {
        if ($value < 0) {
            return $value;
        }

        if (preg_match(self::DISCOUNT_PATTERN, $line) === 1 && preg_match(self::TAX_FEE_PATTERN, $line) !== 1) {
            return -abs($value);
        }

        return abs($value);
    }

    /**
     * Tanda nilai persentase: baris diskon → negatif; baris pajak/biaya →
     * positif; tanpa kata kunci → positif (konservatif).
     */
    private function signedPercent(float $value, string $line): float
    {
        if (preg_match(self::DISCOUNT_PATTERN, $line) === 1 && preg_match(self::TAX_FEE_PATTERN, $line) !== 1) {
            return -abs($value);
        }

        return abs($value);
    }

    /**
     * Cari kombinasi kandidat (satu per baris, baris boleh dilewati/skip)
     * yang jumlahnya = target dalam toleransi. DFS deterministik urut baris.
     *
     * @param  array<int, array<int, float>>  $rowCandidates
     */
    private function canReachTarget(array $rowCandidates, float $target, int $index, float $tolerance): bool
    {
        if ($index >= count($rowCandidates)) {
            return false;
        }

        $candidates = $rowCandidates[$index];

        // Opsi 1: baris ini tidak dipakai (kontribusi 0) — dicoba lebih dulu
        // agar solusi dengan jumlah baris paling sedikit yang menang.
        if ($this->tryRemaining($rowCandidates, $target, $index + 1, 0, $tolerance)) {
            return true;
        }

        foreach ($candidates as $candidate) {
            if ($this->tryRemaining($rowCandidates, $target, $index + 1, $candidate, $tolerance)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluasi kombinasi sisa baris dengan akumulasi `partial` saat ini;
     * prune bila partial sudah tidak mungkin menjangkau target.
     *
     * @param  array<int, array<int, float>>  $rowCandidates
     */
    private function tryRemaining(array $rowCandidates, float $target, int $index, float $partial, float $tolerance): bool
    {
        if (abs($partial - $target) <= $tolerance) {
            return true;
        }

        if ($index >= count($rowCandidates)) {
            return false;
        }

        // Pruning: jangkauan maksimum kontribusi baris tersisa.
        $maxAdd = 0.0;
        $minAdd = 0.0;
        for ($i = $index; $i < count($rowCandidates); $i++) {
            foreach ($rowCandidates[$i] as $c) {
                $maxAdd = max($maxAdd, $c);
                $minAdd = min($minAdd, $c);
            }
        }

        if ($partial + $maxAdd < $target - $tolerance || $partial + $minAdd > $target + $tolerance) {
            return false;
        }

        $candidates = $rowCandidates[$index];
        if ($this->tryRemaining($rowCandidates, $target, $index + 1, $partial, $tolerance)) {
            return true;
        }

        foreach ($candidates as $candidate) {
            if ($this->tryRemaining($rowCandidates, $target, $index + 1, $partial + $candidate, $tolerance)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ambil nominal dengan magnitude terbesar di baris — termasuk yang negatif
     * ("Diskon -5.000" → -5000, "PPN 11% 19.725" → 19725). Memakai pola token
     * & parseMoneyNumber yang sama dengan Helper::extractLargestNumber, bedanya
     * nilai negatif tidak dibuang.
     */
    private function largestMagnitudeNumber(string $line): float
    {
        preg_match_all('/[+-]?\d{1,3}(?:[.,] ?\d{3})*(?:[.,]\d{1,2})?|\d+[.,]\d+/', $line, $matches);

        $best = 0.0;
        foreach ($matches[0] ?? [] as $token) {
            $value = (new Helper)->parseMoneyNumber($token);
            if (abs($value) > abs($best)) {
                $best = $value;
            }
        }

        return $best;
    }
}