<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Support\MoneyFormatter;
use App\Support\ReportFilter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan (total, jumlah, rata-rata) hanya dari expense yang lolos filter
 * Laporan; angka per-user via OwnedByUserScope, amount NULL tidak dihitung.
 */
class LaporanStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected function getStats(): array
    {
        $filter = new ReportFilter($this->pageFilters ?? []);

        // Query dibangun ulang per agregat agar tidak saling memengaruhi
        // (aggregate pada builder yang sama bisa membawa state sisa query).
        $total = (float) $this->filteredExpensesQuery($filter)->sum('amount');
        $count = (int) $this->filteredExpensesQuery($filter)->count();
        $average = $count > 0 ? $total / $count : 0.0;

        // Format Rupiah terpusat (MoneyFormatter): ribuan titik, desimal koma.
        $formatRupiah = fn (float $value): ?string => MoneyFormatter::format($value);

        return [
            Stat::make('Total Pengeluaran', $formatRupiah($total))
                ->description($filter->periodLabel())
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('primary'),

            Stat::make('Jumlah Transaksi', (string) $count)
                ->description($filter->periodLabel())
                ->descriptionIcon(Heroicon::OutlinedShoppingCart)
                ->color('success'),

            Stat::make('Rata-rata per Transaksi', $formatRupiah($average))
                ->description($filter->periodLabel())
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('warning'),
        ];
    }

    /**
     * Query expense ter-filter; dipisah agar bisa dipakai ulang untuk
     * total & jumlah transaksi tanpa membawa state agregat sebelumnya.
     */
    private function filteredExpensesQuery(ReportFilter $filter): \Illuminate\Database\Eloquent\Builder
    {
        return $filter->apply(
            Expense::query()->whereNotNull('amount'),
        );
    }
}
