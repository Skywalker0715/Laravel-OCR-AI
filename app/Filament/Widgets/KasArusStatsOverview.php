<?php

namespace App\Filament\Widgets;

use App\Support\KasArusReport;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan Kas Arus: Total Pemasukan, Total Pengeluaran, dan Saldo
 * (pemasukan − pengeluaran) untuk periode filter halaman KasArus.
 * $pageFilters reaktif membuat kartu ikut berubah setiap filter diganti;
 * angka selalu per-user karena query memakai model dengan OwnedByUserScope.
 */
class KasArusStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected function getStats(): array
    {
        $report = new KasArusReport($this->pageFilters ?? []);
        $totals = $report->totals();
        $period = $report->periodLabel();

        return [
            Stat::make('Total Pemasukan', MoneyFormatter::format($totals['income']) ?? 'Rp 0')
                ->description($period)
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color('success'),

            Stat::make('Total Pengeluaran', MoneyFormatter::format($totals['expense']) ?? 'Rp 0')
                ->description($period)
                ->descriptionIcon(Heroicon::OutlinedShoppingCart)
                ->color('danger'),

            // Warna saldo mengikuti tanda: positif hijau, defisit merah —
            // memberi sinyal cepat tanpa perlu membaca angkanya.
            Stat::make('Saldo', MoneyFormatter::format($totals['saldo']) ?? 'Rp 0')
                ->description($period)
                ->descriptionIcon(Heroicon::OutlinedScale)
                ->color($totals['saldo'] >= 0 ? 'success' : 'danger'),
        ];
    }
}
