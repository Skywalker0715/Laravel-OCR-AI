<?php

namespace App\Support;

/**
 * Formatter tunggal untuk angka Rupiah / angka umum dengan standar penulisan
 * Indonesia, dipakai konsisten di seluruh aplikasi (Expenses, Budgets,
 * Dashboard, global search, notifikasi, dan blade view).
 *
 * Aturan format yang dijamin:
 *  - Pemisah ribuan  : TITIK  (contoh: "Rp 9.300", "Rp 100.000")
 *  - Pemisah desimal : KOMA   (contoh: "Rp 9.300,50")
 *  - Nilai bulat     : TANPA ",00" di belakang ("Rp 300", bukan "Rp 300,00").
 *    Desimal 2 digit hanya tampil bila memang ada nilai sen yang bermakna,
 *    mis. hasil rata-rata pembagian yang tidak bulat.
 */
class MoneyFormatter
{
    /**
     * Format sebuah nominal menjadi string Rupiah bernilai penuh.
     *
     * @param  int|float|string|null  $value  Nominal yang akan diformat.
     * @return string|null  "Rp 9.300" / "Rp 9.300,50", atau null bila kosong.
     */
    public static function format(int|float|string|null $value): ?string
    {
        $number = self::number($value);

        return $number === null ? null : 'Rp '.$number;
    }

    /**
     * Format angka biasa (tanpa simbol mata uang) dengan standar Indonesia.
     * Dipakai untuk kolom non-uang seperti qty (contoh: "2", "2,5").
     *
     * @param  int|float|string|null  $value  Angka yang akan diformat.
     * @return string|null  "9.300" / "9.300,50", atau null bila kosong.
     */
    public static function number(int|float|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        // Nilai bulat → tanpa desimal ("9.300"); nilai pecahan → 2 desimal ("9.300,50").
        // fmod($value, 1) bernilai 0.0 hanya jika tidak ada sisa pecahan sama sekali.
        $decimalPlaces = fmod($value, 1) === 0.0 ? 0 : 2;

        // number_format($value, $decimalPlaces, $decimalSeparator, $thousandsSeparator)
        // dengan separator standar Indonesia: koma untuk desimal, titik untuk ribuan.
        return number_format($value, $decimalPlaces, ',', '.');
    }
}