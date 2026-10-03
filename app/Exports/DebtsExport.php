<?php

namespace App\Exports;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Export daftar utang piutang ke file Excel (.xlsx).
 *
 * Dua hal penting soal performa (lihat TASK 4B):
 *
 *  1. Total "Utang/Piutang Belum Lunas" dihitung dengan SATU query agregat
 *     (SUM + COUNT sekaligus lewat CASE), bukan dengan mengambil semua model
 *     lalu dijumlahkan di PHP. Versi lama menjalankan query dua kali
 *     sekaligus meng-hydrate seluruh model ke memori.
 *
 *  2. Baris detail diekspor lewat FromQuery, sehingga maatwebsite/excel
 *     membacanya per chunk (chunkSize) — jumlah model yang hidup di memori
 *     sekaligus tidak lagi sebanding dengan seluruh tabel.
 *
 * Baris ringkasan (Total Utang / Total Piutang) tetap ditulis di bawah
 * seluruh baris data, persis seperti versi FromCollection sebelumnya —
 * hanya sekarang ditulis lewat event AfterSheet karena baris data tidak
 * lagi tersedia sebagai satu collection utuh di memori.
 */
class DebtsExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithCustomChunkSize, WithEvents, WithHeadings, WithMapping, WithTitle
{
    /** Total sisa seluruh catatan AKTIF bertipe utang — dari SUM di SQL. */
    protected float $totalUtangBelumLunas = 0.0;

    /** Total sisa seluruh catatan AKTIF bertipe piutang — dari SUM di SQL. */
    protected float $totalPiutangBelumLunas = 0.0;

    /** Jumlah baris data hasil filter — dipakai untuk posisi baris ringkasan. */
    protected int $dataRowCount = 0;

    /** Penomoran baris manual, bertambah saat map() dipanggil per baris data. */
    private int $currentRowNumber = 0;

    public function __construct(protected Builder $query)
    {
        $summary = $this->activeTotalsSummary();

        $this->totalUtangBelumLunas = (float) $summary->total_utang;
        $this->totalPiutangBelumLunas = (float) $summary->total_piutang;
        $this->dataRowCount = (int) $summary->total_rows;
    }

    /**
     * Ekspresi SQL untuk sisa tagihan satu baris, identik dengan
     * Debt::remainingAmount() = max(0, amount - paid_amount).
     *
     * Ditulis dengan CASE (bukan GREATEST) karena GREATEST tidak ada di
     * SQLite — dipakai environment test — sedangkan CASE WHEN THEN ELSE
     * tersedia seragam di PostgreSQL, MySQL/MariaDB, dan SQLite.
     */
    private function remainingAmountSql(): string
    {
        $model = $this->query->getModel();
        $difference = $model->qualifyColumn('amount').' - COALESCE('.$model->qualifyColumn('paid_amount').', 0)';

        return "CASE WHEN {$difference} > 0 THEN {$difference} ELSE 0 END";
    }

    /**
     * SATU query agregat yang sekaligus menghasilkan kedua total dan jumlah
     * baris, menggantikan query ganda versi lama.
     */
    private function activeTotalsSummary(): object
    {
        $model = $this->query->getModel();
        $remaining = $this->remainingAmountSql();
        $status = $model->qualifyColumn('status');
        $type = $model->qualifyColumn('type');

        return $this->query
            ->clone()
            ->withoutEagerLoads()
            // Urutan tabel tidak relevan untuk agregat; meletakkannya hanya
            // menambah kerja database, jadi dibuang (reorder() menghapus
            // ORDER BY lama alih-alih menumpuk ORDER BY baru).
            ->reorder()
            ->select([])
            ->selectRaw('COUNT(*) AS total_rows')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$status} <> ? AND {$type} = ? THEN {$remaining} ELSE 0 END), 0) AS total_utang",
                [Debt::STATUS_LUNAS, Debt::TYPE_UTANG]
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$status} <> ? AND {$type} = ? THEN {$remaining} ELSE 0 END), 0) AS total_piutang",
                [Debt::STATUS_LUNAS, Debt::TYPE_PIUTANG]
            )
            ->first();
    }

    /**
     * Query baris detail.
     *
     * Ditambah tie-breaker ORDER BY id: FromQuery dibaca lewat chunk() yang
     * berpindah halaman memakai LIMIT/OFFSET, jadi urutan WAJIB deterministik
     * agar tidak ada baris yang terlewat atau terduplikasi saat nilai sort
     * utama sama-sama. Tie-breaker ini tidak mengubah urutan utama.
     */
    public function query(): Builder
    {
        $this->currentRowNumber = 0;

        return $this->query->clone()->orderBy($this->query->getModel()->qualifyColumn('id'));
    }

    /** Jumlah baris per chunk — cukup besar untuk efisiensi, cukup kecil untuk ringan. */
    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * @return array<int, mixed>
     */
    public function map(mixed $debt): array
    {
        $this->currentRowNumber++;

        return [
            $this->currentRowNumber,
            $debt->typeLabel(),
            $debt->counterparty_name ?? '',
            (float) ($debt->amount ?? 0),
            (float) ($debt->paid_amount ?? 0),
            (float) $debt->remainingAmount(),
            $debt->due_date?->format('d/m/Y') ?? '—',
            $debt->statusLabel(),
            $debt->notes ?? '',
        ];
    }

    public function title(): string
    {
        return 'Utang Piutang';
    }

    public function headings(): array
    {
        return [
            'No',
            'Tipe',
            'Nama Pihak',
            'Jumlah',
            'Dibayar',
            'Sisa',
            'Jatuh Tempo',
            'Status',
            'Catatan',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'D' => '#,##0',
            'E' => '#,##0',
            'F' => '#,##0',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:I1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '10B981']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

                // Baris 1 = judul kolom, baris 2..(N+1) = data, N+2 = pemisah
                // kosong, lalu dua baris ringkasan (pola sama dengan versi
                // FromCollection sebelumnya).
                $utangRow = $this->dataRowCount + 3;
                $piutangRow = $this->dataRowCount + 4;

                $sheet->setCellValue("B{$utangRow}", 'Total Utang Belum Lunas');
                $sheet->setCellValue("F{$utangRow}", $this->totalUtangBelumLunas);

                $sheet->setCellValue("B{$piutangRow}", 'Total Piutang Belum Lunas');
                $sheet->setCellValue("F{$piutangRow}", $this->totalPiutangBelumLunas);

                $sheet->mergeCells("B{$utangRow}:E{$utangRow}");
                $sheet->mergeCells("B{$piutangRow}:E{$piutangRow}");

                $sheet->getStyle("B{$utangRow}:F{$piutangRow}")->applyFromArray([
                    'font' => ['bold' => true],
                ]);

                $sheet->getStyle("B{$utangRow}:F{$utangRow}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']],
                ]);

                $sheet->getStyle("B{$piutangRow}:F{$piutangRow}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']],
                ]);

                $sheet->getStyle("B{$utangRow}:F{$piutangRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // columnFormats() hanya terpasang sampai baris data terakhir
                // (baris ringkasan ditulis di sini, setelahnya), jadi format
                // angka '#,##0' dipasang eksplisit agar tampilan total sama
                // persis dengan versi sebelumnya (tanpa ekor ",00").
                $sheet->getStyle("F{$utangRow}:F{$piutangRow}")
                    ->getNumberFormat()
                    ->setFormatCode($this->columnFormats()['F']);

                // Baris ringkasan menambah lebar kolom B (labelnya panjang).
                // Auto-size normally jalan sebelum event ini, jadi diulang
                // supaya kolom tetap terukur setelah baris tambahan ditulis.
                $event->sheet->autoSize();
            },
        ];
    }
}
