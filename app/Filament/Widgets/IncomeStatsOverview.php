<?php

namespace App\Filament\Widgets;

use App\Models\Income;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Kartu statistik "Total Pemasukan" untuk Dashboard — widget BARU yang
 * terpisah: logika & isi statistik widget lain TIDAK diubah (StatsOverview
 * baris pertama tetap persis seperti sebelumnya). Yang diatur khusus di sini
 * hanya layout ($columnSpan + getColumns()) agar kartu ini sejajar 1/3 lebar
 * dengan 2 kartu DebtStatsOverview di baris kedua — lihat Dashboard::getColumns().
 *
 * Angka = SELURUH pemasukan sepanjang waktu (tanpa filter periode), persis
 * gaya StatsOverview yang menghitung total pengeluaran all-time. Query
 * per-user otomatis lewat OwnedByUserScope pada model Income.
 */
class IncomeStatsOverview extends StatsOverviewWidget
{
    /**
     * Lebar kartu di grid Dashboard (lihat Dashboard::getColumns() = 6 kolom):
     * 2/6 = 1/3 lebar, sehingga sejajar pas dengan 2 kartu milik
     * DebtStatsOverview di baris yang sama. Di bawah breakpoint lg (grid 1 kolom)
     * span kembali ke 1 (penuh) — sama seperti perilaku default widget.
     */
    protected int | string | array $columnSpan = ['default' => 1, 'lg' => 2];

    /**
     * Override jumlah kolom internal widget: bawaan Filament untuk <3 kartu
     * memakai grid 3 kolom, yang membuat SATU-satunya kartu ini cuma selebar
     * 1/3 selnya (1/9 lebar halaman). Dipaksa 1 kolom agar kartu Total
     * Pemasukan mengisi penuh sel 1/3 lebar miliknya di baris kedua Dashboard.
     *
     * @return int
     */
    protected function getColumns(): int | array | null
    {
        return 1;
    }

    protected function getStats(): array
    {
        $total = Income::totalAllTime();

        return [
            Stat::make('Total Pemasukan', MoneyFormatter::format($total) ?? 'Rp 0')
                ->description('Total semua pemasukan sepanjang waktu')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color('success'),
        ];
    }
}
