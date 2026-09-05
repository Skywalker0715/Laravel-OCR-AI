<?php

namespace App\Exports;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export daftar expense hasil filter halaman Laporan ke file .xlsx.
 *
 * Query diterima JADI (sudah ter-filter & ter-urut) dari halaman Laporan
 * (lihat Laporan::orderedExportQuery()), sehingga isi file selalu persis
 * sama dengan tabel yang sedang terlihat oleh user di layar.
 */
class LaporanExpenseExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        return $this->query->with('category');
    }

    public function title(): string
    {
        return 'Laporan Pengeluaran';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Judul', 'Vendor', 'Tanggal', 'Kategori', 'Jumlah'];
    }

    /**
     * Baris data untuk satu expense; kolom Jumlah tetap numerik (float) agar
     * bisa dihitung/NUMBER-formatted oleh Excel, bukan string Rupiah.
     *
     * @param  Expense  $expense
     * @return array<int, mixed>
     */
    public function map($expense): array
    {
        return [
            $expense->title ?? '',
            $expense->vendor ?? '',
            $expense->date_shopping?->format('d/m/Y') ?? '',
            $expense->category?->name ?? 'Tanpa Kategori',
            (float) ($expense->amount ?? 0),
        ];
    }

    /**
     * Format kolom Jumlah (E) dengan pemisah ribuan (tanpa desimal) supaya
     * rapi dibaca, tanpa mengubah nilai numeriknya.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'E' => '#,##0',
        ];
    }
}
