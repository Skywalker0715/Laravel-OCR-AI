<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Peringatan dashboard saat ada job parsing menggantung (>STALE_MINUTES) di tabel
 * `jobs` atau gagal total. Nonaktif pada QUEUE_CONNECTION 'sync' (job inline).
 *
 * PRIVASI (mode UMKM multi-user): hitungan TIDAK dibaca global dari seluruh
 * baris jobs/failed_jobs. Hanya job yang payload-nya merujuk ke expense MILIK
 * USER LOGIN yang dihitung, sehingga user A tidak pernah melihat angka antrian
 * maupun kegagalan milik user B. Untuk instalasi single-user perilaku tampilan
 * tidak berubah — semua job di queue memang milik user itu sendiri.
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
     * masih menunggu/tergantung lebih dari STALE_MINUTES menit — HANYA yang
     * merujuk expense milik user login.
     */
    private static function stalePendingJobsCount(): int
    {
        $expenseIds = self::ownedExpenseIds();

        // Tanpa sesi login / belum punya expense → tidak ada yang boleh
        // dihitung (angka global tidak pernah dibaca sama sekali).
        if ($expenseIds === []) {
            return 0;
        }

        $table = config('queue.connections.database.table', 'jobs');
        $staleCutoff = now()->subMinutes(self::STALE_MINUTES)->getTimestamp();
        $reservedCutoff = now()->subMinutes(self::STALE_MINUTES)->getTimestamp();

        try {
            $payloads = DB::table($table)
                ->where('available_at', '<=', now()->getTimestamp())
                ->where('created_at', '<', $staleCutoff)
                ->where(function ($query) use ($reservedCutoff): void {
                    $query->whereNull('reserved_at')->orWhere('reserved_at', '<', $reservedCutoff);
                })
                // Prefilter SQL: AIParserJob adalah satu-satunya job di app ini,
                // jadi baris lain tidak pernah relevan untuk siapa pun.
                ->where('payload', 'like', '%AIParserJob%')
                ->pluck('payload');

            return self::countOwnedPayloads($payloads, $expenseIds);
        } catch (\Throwable) {
            // Tabel belum dibuat / DB belum migrate — jangan rusak dashboard.
            return 0;
        }
    }

    /**
     * Jumlah job gagal total — HANYA yang merujuk expense milik user login.
     */
    private static function failedJobsCount(): int
    {
        $expenseIds = self::ownedExpenseIds();

        if ($expenseIds === []) {
            return 0;
        }

        try {
            $payloads = DB::table('failed_jobs')
                ->where('payload', 'like', '%AIParserJob%')
                ->pluck('payload');

            return self::countOwnedPayloads($payloads, $expenseIds);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * ID expense milik user yang sedang login.
     *
     * Kembalikan array kosong bila tidak ada sesi login (console/queue), agar
     * widget gagal-aman menuju "tidak tampil" alih-alih membocorkan angka global.
     *
     * @return array<int>
     */
    private static function ownedExpenseIds(): array
    {
        $userId = Auth::id();

        if ($userId === null) {
            return [];
        }

        // withoutGlobalScopes() + filter user_id eksplisit: scoping sengaja
        // ditulis terang-terangan di sini (bukan diam-diam lewat global scope)
        // karena ini baris privasi yang wajib terbaca & teruji.
        return array_map('intval', Expense::query()
            ->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->pluck('id')
            ->all());
    }

    /**
     * Hitung berapa payload yang benar-benar merujuk salah satu expense milik
     * user login ($expenseIds).
     *
     * @param  iterable<array-key, string>  $payloads
     * @param  array<int>  $expenseIds
     */
    private static function countOwnedPayloads(iterable $payloads, array $expenseIds): int
    {
        $count = 0;

        foreach ($payloads as $payload) {
            $expenseId = self::expenseIdFromPayload((string) $payload);

            if ($expenseId !== null && in_array($expenseId, $expenseIds, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Ekstrak ID expense dari payload job queue:
     * JSON → data.command (job ter-serialize oleh SerializesModels) →
     * ModelIdentifier berisi class App\Models\Expense + id-nya.
     *
     * Kembalikan null bila payload bukan job parser struk / tidak merujuk
     * Expense, sehingga baris itu TIDAK pernah dihitung untuk siapa pun.
     */
    private static function expenseIdFromPayload(string $payload): ?int
    {
        $decoded = json_decode($payload, true);

        $command = is_array($decoded)
            ? (string) ($decoded['data']['command'] ?? '')
            : '';

        if ($command === '' || ! str_contains($command, Expense::class)) {
            return null;
        }

        // Format SerializesModels: s:2:"id";i:16; (integer) — varian string
        // (s:2:"id";s:2:"16";) ditangani sebagai cadangan.
        if (preg_match('/s:2:"id";i:(\d+);/', $command, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/s:2:"id";s:\d+:"(\d+)";/', $command, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}