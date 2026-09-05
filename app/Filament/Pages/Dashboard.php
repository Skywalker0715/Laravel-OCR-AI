<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dashboard admin yang menampilkan widget statistik & grafik.
 *
 * Meng-extend Dashboard bawaan Filament, lalu mengganti daftar widget agar
 * hanya widget milik aplikasi ini yang dirender (Widget "Welcome" bawaan
 * sengaja dihapus).
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
