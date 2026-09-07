<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Grafik garis pengeluaran per tanggal belanja (date_shopping), seluruh riwayat.
 * Analisis ber-filter tersedia di halaman Laporan.
 */
class ExpenseLineChart extends ChartWidget
{
    protected ?string $heading = 'Riwayat Pengeluaran';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        // Kelompokkan per tanggal belanja (date_shopping), bukan created_at,
        // dan tanpa filter tanggal apa pun supaya seluruh riwayat tampil.
        $rows = Expense::query()
            ->whereNotNull('date_shopping')
            ->selectRaw('DATE(date_shopping) AS day, COALESCE(SUM(amount), 0) AS total')
            ->groupByRaw('DATE(date_shopping)')
            ->orderByRaw('DATE(date_shopping)')
            ->get();

        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            $labels[] = Carbon::parse($row->day)->translatedFormat('d M Y');
            $values[] = (float) $row->total;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pengeluaran (Rp)',
                    'data' => $values,
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.10)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
