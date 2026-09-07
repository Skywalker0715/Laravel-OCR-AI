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
 * Export daftar expense dari query yang sudah ter-filter & ter-urut
 * (Laporan::orderedExportQuery()) agar isi .xlsx sama dengan tabel di layar.
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

    /** Kolom Jumlah tetap numerik (float) agar bisa dihitung/diformat oleh Excel, bukan string Rupiah. */
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

    /** Format kolom Jumlah (E): pemisah ribuan tanpa desimal, tanpa mengubah nilai numeriknya. */
    public function columnFormats(): array
    {
        return [
            'E' => '#,##0',
        ];
    }
}
