<?php

namespace App\Filament\Widgets;

use App\Models\Category;
use App\Models\Expense;
use Filament\Widgets\ChartWidget;

/**
 * Grafik donat pengeluaran seluruh waktu per kategori; expense tanpa kategori
 * dikelompokkan ke "Tanpa Kategori", warna irisan dari atribut kategori.
 */
class CategoryChart extends ChartWidget
{
    protected ?string $heading = 'Pengeluaran per Kategori';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        // GROUP BY dengan ekspresi yang sama persis di SELECT agar aman lintas
        // database (PostgreSQL/MySQL). Expense tanpa kategori dikelompokkan
        // sebagai category_key 0, lalu diberi label "Tanpa Kategori".
        $rows = Expense::query()
            ->selectRaw('COALESCE(expenses.category_id, 0) AS category_key, COALESCE(SUM(expenses.amount), 0) AS total')
            ->groupByRaw('COALESCE(expenses.category_id, 0)')
            ->get();

        // Muat semua kategori sekaligus (hindari N+1) untuk nama & warna irisan.
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

        // Jaga agar grafik tetap rapi saat belum ada data sama sekali.
        if (count($values) === 0) {
            $labels[] = 'Belum ada data';
            $values[] = 0;
            $colors[] = '#E2E8F0';
        }

        return [
            'datasets' => [
                [
                    'data' => $values,
                    'backgroundColor' => $colors,
                    // Pemisah antar irisan memakai abu netral semi-transparan —
                    // terlihat halus di tema TERANG maupun GELAP. Jangan pakai
                    // '#ffffff' hardcode: di dark mode garis putih terlalu
                    // menyilaukan, dan warna default Filament (primary) akan
                    // membuat tiap irisan bergaris hijau.
                    'borderColor' => 'rgba(148, 163, 184, 0.4)',
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
