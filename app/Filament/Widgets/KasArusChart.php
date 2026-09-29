<?php

namespace App\Filament\Widgets;

use App\Support\KasArusReport;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Grafik batang Pemasukan vs Pengeluaran per bulan (12 bulan terakhir dari
 * periode filter) untuk halaman KasArus. $pageFilters reaktif membuat grafik
 * dirender ulang mengikuti filter terbaru — pola sama dengan LaporanCategoryChart.
 */
class KasArusChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Kas Arus: Pemasukan vs Pengeluaran';

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        return 'Periode: '.(new KasArusReport($this->pageFilters ?? []))->periodLabel();
    }

    protected function getData(): array
    {
        $series = (new KasArusReport($this->pageFilters ?? []))->chartSeries(12);

        // Pengaman bila chart pernah dirender tanpa satu pun baris breakdown
        // (praktisnya mustahil — KasArusReport selalu menyediakan minimal satu
        // baris nol — tapi chart.js tidak boleh menerima labels kosong).
        if ($series['labels'] === []) {
            $series = [
                'labels' => ['Belum ada data'],
                'income' => [0.0],
                'expense' => [0.0],
            ];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pemasukan',
                    'data' => $series['income'],
                    // Hijau brand (#10B981) untuk uang masuk.
                    'backgroundColor' => 'rgba(16, 185, 129, 0.85)',
                    'borderColor' => '#10B981',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Pengeluaran',
                    'data' => $series['expense'],
                    // Merah untuk uang keluar — pasangan warna semantik
                    // yang sama dipakai kartu ringkasan & tabel breakdown.
                    'backgroundColor' => 'rgba(239, 68, 68, 0.75)',
                    'borderColor' => '#EF4444',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }
}
