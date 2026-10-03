<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Exports\DebtsExport;
use App\Filament\Resources\Debts\DebtResource;
use App\Models\Debt;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListDebts extends ListRecords
{
    protected static string $resource = DebtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('danger')
                ->action(fn (): StreamedResponse => $this->exportPdf()),

            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('success')
                ->action(fn (): BinaryFileResponse => $this->exportExcel()),

            CreateAction::make(),
        ];
    }

    /**
     * Ambil query tabel terfilter & terurut untuk keperluan export.
     */
    public function getExportQuery(): Builder
    {
        return $this->getFilteredSortedTableQuery() ?? Debt::query();
    }

    /**
     * Export hasil utang piutang ke PDF (StreamedResponse).
     */
    public function exportPdf(): StreamedResponse
    {
        $pdf = $this->buildPdfDocument($this->getExportQuery());

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Export hasil utang piutang ke Excel (.xlsx) via maatwebsite/excel.
     *
     * Memakai Excel::download(), bukan Excel::raw(): raw() membangun SELURUH
     * file .xlsx sebagai satu string di memori PHP lalu dicetak lewat print()
     * (masalah untuk data besar). download() menulis file ke temporary file
     * lalu mengirimkannya sebagai BinaryFileResponse yang di-stream oleh
     * server — isi file tidak berubah, hanya cara pengirimannya.
     */
    public function exportExcel(): BinaryFileResponse
    {
        return Excel::download(
            new DebtsExport($this->getExportQuery()),
            $this->exportFilename('xlsx'),
            ExcelWriter::XLSX,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Batas baris DETAIL yang dicetak di PDF utang piutang.
     *
     * Kartu ringkasan (Total Utang/Piutang Belum Lunas) TIDAK terpengaruh
     * batas ini — keduanya dihitung dari SUM di SQL atas seluruh data hasil
     * filter. Batas ini hanya menjaga PDF tetap ringan, dengan catatan
     * truncation yang tercetak jelas di dalam PDF.
     */
    private const PDF_DETAIL_ROW_LIMIT = 1000;

    /**
     * Susun dokumen PDF utang piutang (kop, ringkasan kartu utang & piutang
     * belum lunas, dan tabel detail).
     */
    public function buildPdfDocument(Builder $query): \Barryvdh\DomPDF\PDF
    {
        // Satu query agregat untuk total + jumlah baris; dihitung dari SQL
        // agar angka di PDF tetap jujur walau baris detail dipotong.
        $summary = $this->activeTotals($query);
        $totalRowCount = $summary['count'];

        $debts = (clone $query)->limit(self::PDF_DETAIL_ROW_LIMIT)->get();

        return Pdf::loadView('exports.utang-piutang-pdf', [
            'userName' => auth()->user()?->name ?? '—',
            'generatedAt' => now()->format('d/m/Y H:i'),
            'debts' => $debts,
            'totalUtangBelumLunas' => $summary['utang'],
            'totalPiutangBelumLunas' => $summary['piutang'],
            'totalRowCount' => $totalRowCount,
            'detailLimit' => self::PDF_DETAIL_ROW_LIMIT,
            'isTruncated' => $debts->count() < $totalRowCount,
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Total sisa catatan AKTIF per tipe + jumlah baris, dari SATU query agregat SQL.
     *
     * Sisa = max(0, amount - paid_amount), ditulis dengan CASE (bukan
     * GREATEST) karena GREATEST tidak tersedia di SQLite yang dipakai test,
     * sedangkan CASE WHEN seragam di semua driver yang didukung.
     *
     * @return array{utang: float, piutang: float, count: int}
     */
    private function activeTotals(Builder $query): array
    {
        $model = $query->getModel();
        $difference = $model->qualifyColumn('amount').' - COALESCE('.$model->qualifyColumn('paid_amount').', 0)';
        $remaining = "CASE WHEN {$difference} > 0 THEN {$difference} ELSE 0 END";

        $row = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('COUNT(*) AS total_rows')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$model->qualifyColumn('status')} <> ? AND {$model->qualifyColumn('type')} = ? THEN {$remaining} ELSE 0 END), 0) AS total_utang",
                [Debt::STATUS_LUNAS, Debt::TYPE_UTANG]
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$model->qualifyColumn('status')} <> ? AND {$model->qualifyColumn('type')} = ? THEN {$remaining} ELSE 0 END), 0) AS total_piutang",
                [Debt::STATUS_LUNAS, Debt::TYPE_PIUTANG]
            )
            ->first();

        return [
            'utang' => (float) $row->total_utang,
            'piutang' => (float) $row->total_piutang,
            'count' => (int) $row->total_rows,
        ];
    }

    /**
     * Nama file export yang deskriptif dan mencakup timestamp.
     */
    private function exportFilename(string $extension): string
    {
        $timestamp = now()->format('Ymd-His');

        return "laporan-utang-piutang-{$timestamp}.{$extension}";
    }
}

