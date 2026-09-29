<?php

namespace App\Exports;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Builder;
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
 * Export daftar utang piutang ke file Excel (.xlsx).
 */
class DebtsExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithTitle
{
    protected Collection $records;
    protected float $totalUtangBelumLunas = 0.0;
    protected float $totalPiutangBelumLunas = 0.0;
    protected int $dataRowCount = 0;

    public function __construct(protected Builder $query)
    {
        $totalsQuery = clone $this->query;
        $activeRecords = $totalsQuery->where('status', '!=', Debt::STATUS_LUNAS)->get();

        $this->totalUtangBelumLunas = (float) $activeRecords
            ->where('type', Debt::TYPE_UTANG)
            ->sum(fn (Debt $d): float => $d->remainingAmount());

        $this->totalPiutangBelumLunas = (float) $activeRecords
            ->where('type', Debt::TYPE_PIUTANG)
            ->sum(fn (Debt $d): float => $d->remainingAmount());

        $this->records = $this->query->get();
        $this->dataRowCount = $this->records->count();
    }

    public function collection(): Collection
    {
        $rows = new Collection;

        foreach ($this->records as $index => $debt) {
            $rows->push([
                $index + 1,
                $debt->typeLabel(),
                $debt->counterparty_name ?? '',
                (float) ($debt->amount ?? 0),
                (float) ($debt->paid_amount ?? 0),
                (float) $debt->remainingAmount(),
                $debt->due_date?->format('d/m/Y') ?? '—',
                $debt->statusLabel(),
                $debt->notes ?? '',
            ]);
        }

        $rows->push(['', '', '', '', '', '', '', '', '']);

        $rows->push([
            '',
            'Total Utang Belum Lunas',
            '',
            '',
            '',
            $this->totalUtangBelumLunas,
            '',
            '',
            '',
        ]);

        $rows->push([
            '',
            'Total Piutang Belum Lunas',
            '',
            '',
            '',
            $this->totalPiutangBelumLunas,
            '',
            '',
            '',
        ]);

        return $rows;
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

                $utangRow = $this->dataRowCount + 3;
                $piutangRow = $this->dataRowCount + 4;

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
            },
        ];
    }
}
