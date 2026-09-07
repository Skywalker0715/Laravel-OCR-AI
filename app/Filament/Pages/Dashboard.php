<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dashboard admin: memakai widget kustom aplikasi (Widget "Welcome" bawaan dihapus).
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\PendingParsingJobsAlert::class,
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\ExpenseLineChart::class,
            \App\Filament\Widgets\CategoryChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        // 2 kolom: statistik rata penuh di atas, lalu dua grafik berdampingan.
        return 2;
    }
}
