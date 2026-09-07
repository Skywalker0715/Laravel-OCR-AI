<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Peringatan dashboard saat ada job parsing menggantung (>STALE_MINUTES) di tabel
 * `jobs` atau gagal total. Nonaktif pada QUEUE_CONNECTION 'sync' (job inline).
 */
class PendingParsingJobsAlert extends Widget
{
    protected static ?int $sort = -5;

    /** Tampil selebar dashboard. */
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.pending-parsing-jobs-alert';

    /** Ambang umur job (menit) sebelum dianggap "macet". */
    private const STALE_MINUTES = 5;

    public static function canView(): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        return self::stalePendingJobsCount() > 0 || self::failedJobsCount() > 0;
    }

    public function getViewData(): array
    {
        $pending = self::stalePendingJobsCount();
        $failed = self::failedJobsCount();

        $parts = [];
        if ($pending > 0) {
            $parts[] = "{$pending} data struk belum diproses";
        }
        if ($failed > 0) {
            $parts[] = "{$failed} job gagal";
        }

        return [
            'heading' => 'Antrian parsing terhambat',
            'description' => 'Sudah lebih dari '.self::STALE_MINUTES.' menit ada data yang belum diproses.',
            'message' => implode(' & ', $parts)
                .'. Pastikan queue worker aktif (php artisan queue:work), lalu '
                .'jalankan ulang parsing lewat tombol "Proses Ulang OCR & AI" untuk expense terkait.',
            'indexUrl' => ExpenseResource::getUrl('index'),
        ];
    }

    /**
     * Jumlah job yang seharusnya sudah diproses (available_at lewat) tapi
     * masih menunggu/tergantung lebih dari STALE_MINUTES menit.
     */
    private static function stalePendingJobsCount(): int
    {
        $table = config('queue.connections.database.table', 'jobs');
        $staleCutoff = now()->subMinutes(self::STALE_MINUTES)->getTimestamp();
        $reservedCutoff = now()->subMinutes(self::STALE_MINUTES)->getTimestamp();

        try {
            return (int) DB::table($table)
                ->where('available_at', '<=', now()->getTimestamp())
                ->where('created_at', '<', $staleCutoff)
                ->where(function ($query) use ($reservedCutoff): void {
                    $query->whereNull('reserved_at')->orWhere('reserved_at', '<', $reservedCutoff);
                })
                ->count();
        } catch (\Throwable) {
            // Tabel belum dibuat / DB belum migrate — jangan rusak dashboard.
            return 0;
        }
    }

    private static function failedJobsCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}