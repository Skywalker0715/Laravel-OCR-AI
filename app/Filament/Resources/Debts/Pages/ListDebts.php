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
                ->action(fn (): StreamedResponse => $this->exportExcel()),

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
     */
    public function exportExcel(): StreamedResponse
    {
        $content = (string) Excel::raw(new DebtsExport($this->getExportQuery()), ExcelWriter::XLSX);

        return response()->streamDownload(
            fn () => print ($content),
            $this->exportFilename('xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Susun dokumen PDF utang piutang (kop, ringkasan kartu utang & piutang belum lunas, dan tabel detail).
     */
    public function buildPdfDocument(Builder $query): \Barryvdh\DomPDF\PDF
    {
        $debts = $query->get();

        $activeDebts = $debts->where('status', '!=', Debt::STATUS_LUNAS);

        $totalUtangBelumLunas = (float) $activeDebts
            ->where('type', Debt::TYPE_UTANG)
            ->sum(fn (Debt $d): float => $d->remainingAmount());

        $totalPiutangBelumLunas = (float) $activeDebts
            ->where('type', Debt::TYPE_PIUTANG)
            ->sum(fn (Debt $d): float => $d->remainingAmount());

        return Pdf::loadView('exports.utang-piutang-pdf', [
            'userName' => auth()->user()?->name ?? '—',
            'generatedAt' => now()->format('d/m/Y H:i'),
            'debts' => $debts,
            'totalUtangBelumLunas' => $totalUtangBelumLunas,
            'totalPiutangBelumLunas' => $totalPiutangBelumLunas,
        ])->setPaper('a4', 'portrait');
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

