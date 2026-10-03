<?php

namespace App\Support;

use App\Models\Budget;
use Carbon\CarbonImmutable;

/**
 * Ekspresi SQL untuk mengubah kolom tanggal menjadi kunci bulan format 'Y-m'.
 *
 * Dipakai bersama oleh KasArusReport (agregasi per bulan) dan ExpenseLineChart
 * (rollup per bulan) supaya keduanya memakai formulas yang sama persis —
 * dan menghasilkan angka yang sama persis — di semua driver yang didukung.
 *
 * Fungsi tanggal bawaan tiap database berbeda nama & sintaksnya, sehingga
 * penulisan 'Y-m' harus dilakukan lewat fungsi native masing-masing driver.
 * Driver yang tidak ada di tabel ini (atau SQL Server yang belum teruji)
 * mengembalikan null: pemanggil wajib memakai agregasi PHP sebagai fallback,
 * bukan menebak sintaks SQL yang belum diverifikasi.
 */
final class MonthExpression
{
    /**
     * Ekspresi SQL untuk kunci bulan 'Y-m' pada $qualifiedColumn.
     *
     * @param  string  $driver  Nama driver koneksi (lihat Connection::getDriverName()).
     * @param  string  $qualifiedColumn  Kolom tanggal yang sudah di-qualify, mis. 'incomes.date_received'.
     * @return string|null  Ekspresi SQL, atau null bila driver tidak punya padanan yang terverifikasi.
     */
    public static function for(string $driver, string $qualifiedColumn): ?string
    {
        return match ($driver) {
            // PostgreSQL: to_char() menerima kolom date/timestamp.
            'pgsql' => "to_char({$qualifiedColumn}, 'YYYY-MM')",
            // MySQL/MariaDB: format specifier memakai '%Y-%m'.
            'mysql', 'mariadb' => "DATE_FORMAT({$qualifiedColumn}, '%Y-%m')",
            // SQLite: strftime() dipakai hanya oleh caller yang memverifikasinya
            // (lihat catatan fallback di KasArusReport).
            'sqlite' => "strftime('%Y-%m', {$qualifiedColumn})",
            default => null,
        };
    }

    /**
     * Label bulan Bahasa Indonesia ("Juli 2026") dari kunci 'Y-m'.
     *
     * Dipakai oleh semua tampilan yang menampilkan deret bulan (baris breakdown
     * Kas Arus & sumbu X grafik Dashboard) supaya format labelnya sama persis
     * dan memakai daftar bulan yang sama dengan filter halaman Laporan.
     */
    public static function monthLabel(string $monthKey): string
    {
        $year = substr($monthKey, 0, 4);
        $month = (int) substr($monthKey, 5, 2);

        return (Budget::monthOptions()[$month] ?? (string) $month).' '.$year;
    }

    /** Ubah nilai tanggal (Carbon/string) menjadi kunci bulan 'Y-m'. */
    public static function keyOf(CarbonImmutable|string $date): string
    {
        return $date instanceof CarbonImmutable
            ? $date->format(self::format())
            : CarbonImmutable::parse($date)->format(self::format());
    }

    /** Format kunci bulan yang dipakai seluruh kelas ini. */
    public static function format(): string
    {
        return 'Y-m';
    }
}