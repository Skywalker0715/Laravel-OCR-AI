<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan keuangan seluruh waktu per user (via OwnedByUserScope); analisis
 * ber-filter tersedia di halaman Laporan.
 */
class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        // Hitung dari SELURUH riwayat expense milik user, tanpa filter tanggal
        // apa pun (bukan hanya bulan/tahun berjalan).
        $total = (float) Expense::query()->sum('amount');
        $count = Expense::query()->count();
        $average = $count > 0 ? $total / $count : 0;

        // Format Rupiah terpusat (MoneyFormatter): ribuan titik, desimal koma,
        // dan nilai bulat tanpa ",00" (contoh: "Rp 9.300", bukan "Rp 9.300,00").
        $formatRupiah = fn (float $value): ?string => MoneyFormatter::format($value);

        return [
            Stat::make('Total Pengeluaran Keseluruhan', $formatRupiah($total))
                ->description('Total semua transaksi tercatat')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('primary'),

            Stat::make('Jumlah Transaksi Keseluruhan', (string) $count)
                ->description('Transaksi tercatat sepanjang waktu')
                ->descriptionIcon(Heroicon::OutlinedShoppingCart)
                ->color('success'),

            Stat::make('Rata-rata per Transaksi', $formatRupiah($average))
                ->description('Rata-rata dari seluruh transaksi')
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('warning'),
        ];
    }
}
