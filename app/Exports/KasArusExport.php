<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Export laporan Kas Arus ke file Excel (.xlsx): tabelrilis pemasukan vs
 * pengeluaran PER BULAN + blok ringkasan (Total Pemasukan, Total Pengeluaran,
 * Saldo Akhir) untuk seluruh periode yang di-export.
 *
 * Terpisah penuh dari LaporanExpenseExport (laporan pengeluaran) dan
 * DebtsExport (utang piutang) — menerima data yang sudah di-agregasi oleh
 * KasArusReport, sehingga tidak bergantung pada query model manapun dan
 * otomatis mengikuti filter aktif halaman KasArus.
 *
 * Format kolom uang '#,##0' (pemisah ribuan tanpa desimal) menjaga angka
 * tampil TANPA ",00" di belakang — konsisten dengan standar export lain di
 * aplikasi ini — sambil tetap tersimpan numerik agar bisa dihitung di Excel.
 */
class KasArusExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithTitle
{
    /** Jumlah baris data bulanan (tanpa baris judul & blok ringkasan) — dipakai menentukan baris ringkasan saat styling. */
    private int $dataRowCount;

    /**
     * @param  Collection<int, array{key?: string, label: string, income: float, expense: float, saldo: float}>  $rows  Breakdown per bulan dari KasArusReport::monthlyBreakdown().
     * @param  array{income: float, expense: float, saldo: float}  $summary  Total seluruh periode dari KasArusReport::totals().
     */
    public function __construct(
        protected Collection $rows,
        protected array $summary,
    ) {
        $this->dataRowCount = $rows->count();
    }

    /**
     * Baris data: label bulan + 3 angka numerik, lalu blok ringkasan.
     *
     * @return Collection<int, array<int, float|string>>
     */
    public function collection(): Collection
    {
        $rows = new Collection;

        foreach ($this->rows as $row) {
            $rows->push([
                (string) ($row['label'] ?? ''),
                (float) ($row['income'] ?? 0),
                (float) ($row['expense'] ?? 0),
                (float) ($row['saldo'] ?? 0),
            ]);
        }

        // Pemisah kosong sebelum blok ringkasan (pola sama dengan DebtsExport).
        $rows->push(['', '', '', '']);

        // Ringkasan seluruh periode yang di-export. Nilai sengaja diletakkan
        // tepat di kolom B/C/D — masing-masing kolom uang — agar kena format
        // '#,##0' (tampil tanpa ",00" tapi tetap numerik & bisa dijumlah Excel).
        $rows->push(['Total Pemasukan', (float) ($this->summary['income'] ?? 0), '', '']);
        $rows->push(['Total Pengeluaran', '', (float) ($this->summary['expense'] ?? 0), '']);
        $rows->push(['Saldo Akhir', '', '', (float) ($this->summary['saldo'] ?? 0)]);

        return $rows;
    }

    public function title(): string
    {
        return 'Kas Arus';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Bulan', 'Pemasukan', 'Pengeluaran', 'Saldo'];
    }

    /**
     * Format kolom uang B–D: pemisah ribuan TANPA desimal, sehingga angka
     * tidak pernah tampil dengan ekor ",00" — konsisten dengan standar
     * export aplikasi (LaporanExpenseExport & DebtsExport memakai '#,##0').
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'B' => '#,##0',
            'C' => '#,##0',
            'D' => '#,##0',
        ];
    }

    /**
     * Styling sheet: judul dengan aksen hijau brand + blok ringkasan tebal
     * (hijau bila saldo positif, merah bila defisit).
     *
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:D1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '10B981']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

                // Posisi blok ringkasan: baris 1 = judul, 2..N+1 = data,
                // N+2 = pemisah kosong, N+3..N+5 = tiga baris ringkasan.
                $firstSummaryRow = $this->dataRowCount + 3;
                $lastSummaryRow = $firstSummaryRow + 2;

                $sheet->getStyle("A{$firstSummaryRow}:D{$lastSummaryRow}")->applyFromArray([
                    'font' => ['bold' => true],
                ]);

                $sheet->getStyle("A{$firstSummaryRow}:D{$lastSummaryRow}")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                // Baris Saldo Akhir diberi aksen: hijau bila positif, merah bila defisit.
                $sheet->getStyle("A{$lastSummaryRow}:D{$lastSummaryRow}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => ((float) ($this->summary['saldo'] ?? 0)) >= 0 ? 'DCFCE7' : 'FEE2E2'],
                    ],
                ]);
            },
        ];
    }
}
