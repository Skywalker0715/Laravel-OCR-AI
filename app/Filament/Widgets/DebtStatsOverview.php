<?php

namespace App\Filament\Widgets;

use App\Models\Debt;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Kartu statistik Utang Piutang untuk Dashboard — widget BARU yang terpisah
 * dari widget lama (StatsOverview, ExpenseLineChart, CategoryChart tidak
 * diubah sama sekali).
 *
 * Angka yang ditampilkan adalah SISA tagihan dari catatan yang belum lunas
 * (amount - paid_amount), bukan nominal awal, karena itu angka yang benar-
 * benar perlu ditindaklanjuti. Query per-user otomatis lewat
 * OwnedByUserScope pada model Debt.
 */
class DebtStatsOverview extends StatsOverviewWidget
{
    /**
     * Lebar widget di grid Dashboard (lihat Dashboard::getColumns() = 6 kolom):
     * 4/6 = 2/3 lebar, dibagi rata jadi 2 kartu @1/3 oleh getColumns() di bawah —
     * sehingga Total Utang & Total Piutang sejajar seimbang dengan Total
     * Pemasukan (1/3) di baris yang sama. Di bawah lg (grid 1 kolom) span
     * kembali ke 1 (penuh).
     */
    protected int | string | array $columnSpan = ['default' => 1, 'lg' => 4];

    /**
     * Override jumlah kolom internal widget: bawaan Filament untuk <3 kartu
     * memakai pola container-query 3 kolom ('@xl' / '!@lg') yang di lebar 2/3
     * widget ini justru membuat 2 kartu tidak selebar 1/3 penuh. Dipaksa
     * viewport-based 2 kolom di semua ukuran layar agar masing-masing kartu
     * selalu tepat 1/3 lebar Dashboard — sejajar dengan Total Pemasukan.
     *
     * @return array<string, int>
     */
    protected function getColumns(): int | array | null
    {
        return ['default' => 2, 'lg' => 2];
    }

    protected function getStats(): array
    {
        $totalUtang = Debt::activeTotalFor(Debt::TYPE_UTANG);
        $totalPiutang = Debt::activeTotalFor(Debt::TYPE_PIUTANG);

        return [
            Stat::make('Total Utang Aktif', MoneyFormatter::format($totalUtang) ?? 'Rp 0')
                ->description('Sisa utang yang belum lunas')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingDown)
                ->color('danger'),

            Stat::make('Total Piutang Aktif', MoneyFormatter::format($totalPiutang) ?? 'Rp 0')
                ->description('Sisa piutang yang belum tertagih')
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color('success'),
        ];
    }
}
