<?php

namespace App\Services\Parsing;

use Illuminate\Support\Facades\Log;

/**
 * Rekonsiliasi & validasi silang nominal pada jalur fallback regex: koreksi
 * "digit nyelip" pada item terhadap total struk (BUG 4) dan validasi total
 * tercetak terhadap penjumlahan item yang sudah direkonsiliasi.
 *
 * Diekstrak dari AIParserService (refactor struktural — perilaku identik).
 * Flag $scaledQtyStruk (state milik RegexItemParser, struk grosir dengan
 * qty ber-skala ribuan) diterima sebagai parameter karena memengaruhi
 * kedua metode.
 */
class TotalReconciler
{
    /**
     * BUG 4 — koreksi "digit nyelip" item dengan validasi silang terhadap total
     * struk (mis. "Rp 1.600" terbaca "Rp 11600"). Hanya item single-price yang
     * boleh dikoreksi (struk diskon/ber-subtotal tak tersentuh), selisih baru
     * <= 30% dari semula, maksimal satu item.
     */
    public function reconcileItemsAgainstTotal(array $items, float $total, bool $scaledQtyStruk): array
    {
        if ($scaledQtyStruk || $total <= 0 || count($items) < 2) {
            return $items;
        }

        $sum = 0.0;
        foreach ($items as $item) {
            $sum += (float) ($item['subtotal'] ?? 0);
        }

        $gap = abs($sum - $total);
        if ($gap < 0.01) {
            return $items;
        }

        $best = null;

        foreach ($items as $index => $item) {
            if (($item['single_price'] ?? false) !== true) {
                continue;
            }

            $price = (float) ($item['price'] ?? 0);
            $digits = (string) (int) $price;

            // Nominal harus bilangan bulat >= 4 digit agar varian hapus-digit
            // bermakna (mis. "11600" -> "1600"), bukan pecahan/ber-ribuan.
            if ($price < 1000 || abs($price - (float) $digits) > 0.01 || strlen($digits) < 4) {
                continue;
            }

            foreach ($this->singleDigitDeletionVariants($digits) as $variant) {
                $newPrice = (float) $variant;
                if ($newPrice < 100) {
                    continue;
                }

                $newSum = $sum - $price + $newPrice;
                $newGap = abs($newSum - $total);

                if ($newGap < $gap * 0.3 && ($best === null || $newGap < $best['gap'])) {
                    $best = [
                        'index' => $index,
                        'gap' => $newGap,
                        'price' => $newPrice,
                    ];
                }
            }
        }

        if ($best === null) {
            return $items;
        }

        Log::warning(sprintf(
            'BUG 4 digit-nyelip terkoreksi pada item "%s": %s -> %s (validasi silang total struk).',
            $items[$best['index']]['name'] ?? '?',
            $items[$best['index']]['price'] ?? '?',
            $best['price'],
        ));

        $items[$best['index']]['price'] = $best['price'];
        $items[$best['index']]['subtotal'] = $best['price'];

        return $items;
    }

    /** Semua nilai unik hasil menghapus SATU digit dari token angka
     * ("11600" -> {"1600", "1100", "1160"}).
     * @return array<int, string>
     */
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

    /**
     * Validasi silang TOTAL tercetak vs penjumlahan item yang sudah direkonsiliasi.
     * Total diganti hanya bila lolos uji digit-nyelip (salah-baca satu digit bukan
     * terdepan, atau kelebihan satu digit nyelip); selain itu total tercetak
     * dipertahankan dan mismatch hanya dicatat ke log.
     */
    public function validateTotalAgainstItems(array $items, float $total, bool $scaledQtyStruk): float
    {
        if ($scaledQtyStruk || $total <= 0 || $items === []) {
            return $total;
        }

        $sumItems = 0.0;
        foreach ($items as $item) {
            $sumItems += (float) ($item['subtotal'] ?? 0);
        }

        if ($sumItems <= 0 || abs($sumItems - $total) < 0.01) {
            return $total;
        }

        Log::warning(sprintf(
            'Fallback: jumlah subtotal item (%s) != total tercetak (%s), selisih %s. Total tercetak tetap dipakai kecuali lolos uji digit-nyelip.',
            number_format($sumItems, 2, ',', '.'),
            number_format($total, 2, ',', '.'),
            number_format($total - $sumItems, 2, ',', '.'),
        ));

        $sumDigits = (string) (int) round($sumItems);
        $totalDigits = (string) (int) round($total);

        $adopted = false;

        // Pola 1: salah baca SATU digit di posisi bukan terdepan
        // ("146975" terbaca "148975" — beda tepat di posisi ke-3).
        if (strlen($sumDigits) === strlen($totalDigits)) {
            $diff = 0;
            $firstDiffAt = -1;
            for ($i = 0, $n = strlen($totalDigits); $i < $n; $i++) {
                if ($sumDigits[$i] !== $totalDigits[$i]) {
                    $diff++;
                    $firstDiffAt = $firstDiffAt === -1 ? $i : $firstDiffAt;
                }
            }

            // Digit terdepan sengaja dikecualikan: beda digit terdepan
            // mengubah besar nilai drastis (>= ~10%) dan lebih mungkin
            // selisih komposisi yang sah daripada salah baca OCR.
            $relativeDiff = $total > 0 ? abs($total - $sumItems) / $total : 1.0;
            $adopted = $diff === 1 && $firstDiffAt >= 1 && $relativeDiff <= 0.25;
        }

        // Pola 2: total kelebihan satu digit nyelip di depan/tengah.
        if (! $adopted) {
            foreach ($this->singleDigitDeletionVariants($totalDigits) as $variant) {
                if ($variant === $sumDigits) {
                    $adopted = true;
                    break;
                }
            }
        }

        if (! $adopted) {
            return $total;
        }

        Log::warning(sprintf(
            'Digit-nyelip pada baris TOTAL terkoreksi: %s -> %s (dipakai penjumlahan item yang sudah tervalidasi silang).',
            $totalDigits,
            $sumDigits,
        ));

        return round($sumItems, 2);
    }
}
