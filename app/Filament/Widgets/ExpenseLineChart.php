<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Support\MonthExpression;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Grafik garis pengeluaran per BULAN, mencakup seluruh riwayat.
 *
 * Kenapa diubah dari per tanggal menjadi per bulan:
 *  - Versi lama memakai GROUP BY DATE(date_shopping) atas SELURUH riwayat.
 *    Pada akun yang sudah lama dipakai, ini menghasilkan ribuan titik data,
 *    dan line chart dengan ribuan titik juga lambat digambar di browser user.
 *  - Rollup per bulan membuat jumlah titik sama dengan jumlah bulan yang punya
 *    transaksi — kecil dan stabil berapa pun jumlah datanya. Total per bulan
 *    tetap persis sama dengan penjumlahan versi per tanggal, jadi tidak ada
 *    angka yang hilang; hanya pengelompokannya yang lebih kasar.
 *  - Label sumbu memakai nama bulan ("Juli 2026") lewat MonthExpression, sama
 *    persis dengan baris breakdown di halaman Kas Arus, dan rollup-nya memakai
 *    ekspresi tanggal yang sama juga — satu sumber kebenaran untuk "bulan ini".
 *
 * Analisis ber-filter per kategori / per periode tetap tersedia di halaman
 * Laporan dan Kas Arus; widget ini sengaja tetap menampilkan seluruh riwayat.
 */
class ExpenseLineChart extends ChartWidget
{
    /**
     * Grid Dashboard memakai 6 kolom (lihat Dashboard::getColumns()): grafik
     * mengambil 3/6 = 1/2 lebar agar tetap berdampingan 50:50 dengan
     * CategoryChart seperti sebelumnya. Di bawah lg (grid 1 kolom) span = 1.
     */
    protected int | string | array $columnSpan = ['default' => 1, 'lg' => 3];

    /** Heading menyebut perlubannya supaya granularity grafik jelas bagi pembaca. */
    protected ?string $heading = 'Riwayat Pengeluaran per Bulan';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $rows = $this->monthlyTotals();

        $labels = [];
        $values = [];

        foreach ($rows as $monthKey => $total) {
            $labels[] = MonthExpression::monthLabel((string) $monthKey);
            $values[] = (float) $total;
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

    /**
     * Total pengeluaran per bulan sebagai map 'Y-m' => total, urut menaik.
     *
     * Mengembalikan SATU baris per bulan — bukan satu baris per transaksi —
     * sehingga bebannya tidak ikut bertambah bersama jumlah transaksi.
     *
     * @return Collection<string, float>
     */
    private function monthlyTotals(): Collection
    {
        $query = Expense::query()
            ->whereNotNull('date_shopping')
            ->reorder();

        $dateColumn = $query->getModel()->qualifyColumn('date_shopping');
        $expression = MonthExpression::for(
            $query->getConnection()->getDriverName(),
            $dateColumn,
        );

        return $expression === null
            ? $this->monthlyTotalsInPhp($query, $dateColumn)
            : $this->monthlyTotalsInSql($query, $expression);
    }

    /**
     * Jalur SQL: GROUP BY ekspresi bulan, SUM dihitung di database.
     *
     * @return Collection<string, float>
     */
    private function monthlyTotalsInSql(Builder $query, string $expression): Collection
    {
        $amount = $query->getModel()->qualifyColumn('amount');

        return $query
            ->selectRaw("{$expression} AS month_key, COALESCE(SUM({$amount}), 0) AS month_total")
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get()
            ->mapWithKeys(fn (Expense $row): array => [
                (string) $row->getAttribute('month_key') => (float) $row->getAttribute('month_total'),
            ]);
    }

    /**
     * Jalur fallback PHP untuk driver tanpa ekspresi bulan native
     * (pola yang sama persis dengan KasArusReport).
     *
     * @return Collection<string, float>
     */
    private function monthlyTotalsInPhp(Builder $query, string $dateColumn): Collection
    {
        $amount = $query->getModel()->qualifyColumn('amount');

        return $query
            ->select([$dateColumn, $amount])
            ->get()
            ->groupBy(fn (Expense $row): string => $row->date_shopping->format(MonthExpression::format()))
            ->map(fn (Collection $rows): float => (float) $rows->sum('amount'));
    }
}
