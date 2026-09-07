<?php

namespace App\Filament\Widgets;

use App\Models\Category;
use App\Models\Expense;
use App\Support\ReportFilter;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Grafik bar pengeluaran per kategori untuk filter Laporan; $pageFilters reaktif
 * membuat grafik dirender ulang mengikuti filter terbaru. Tanpa kategori → "Tanpa Kategori".
 */
class LaporanCategoryChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Pengeluaran per Kategori';

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): ?string
    {
        return 'Periode: '.(new ReportFilter($this->pageFilters ?? []))->periodLabel();
    }

    protected function getData(): array
    {
        $filter = new ReportFilter($this->pageFilters ?? []);

        $rows = $filter->apply(
            Expense::query()
                ->whereNotNull('amount')
                ->selectRaw('COALESCE(expenses.category_id, 0) AS category_key, COALESCE(SUM(expenses.amount), 0) AS total'),
        )
            ->groupByRaw('COALESCE(expenses.category_id, 0)')
            ->get();

        // Muat semua kategori sekaligus (hindari N+1) untuk nama & warna batang.
        $ids = $rows->pluck('category_key')->filter()->unique()->values()->all();
        $categories = Category::query()->whereIn('id', $ids)->get()->keyBy('id');

        $labels = [];
        $values = [];
        $colors = [];

        foreach ($rows as $row) {
            $category = $categories[$row->category_key] ?? null;

            if ($category) {
                $labels[] = $category->name;
                $colors[] = $category->color ?? '#CBD5E1';
            } else {
                $labels[] = 'Tanpa Kategori';
                $colors[] = '#CBD5E1';
            }

            $values[] = (float) $row->total;
        }

        // Jaga agar grafik tetap rapi saat belum ada data yang lolos filter.
        if (count($values) === 0) {
            $labels[] = 'Belum ada data';
            $values[] = 0;
            $colors[] = '#E2E8F0';
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pengeluaran',
                    'data' => $values,
                    'backgroundColor' => $colors,
                    // Pemisah antar irisan memakai abu netral semi-transparan —
                    // terlihat halus di tema TERANG maupun GELAP (sama seperti
                    // CategoryChart). Jangan pakai '#ffffff' hardcode: di dark
                    // mode garis putih terlalu menyilaukan.
                    'borderColor' => 'rgba(148, 163, 184, 0.4)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
