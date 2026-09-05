<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pemantau pemakaian budget + pengirim notifikasi peringatan budget.
 *
 * Dipanggil setiap kali sebuah expense disimpan/diupdate (lihat model event
 * pada App\Models\Expense) maupun setelah parsing struk mengisi amount.
 * Untuk setiap budget yang relevan dengan expense tersebut (budget khusus
 * kategori + budget umum pada periode yang sama), total pemakaian dihitung
 * ulang lalu dua ambang batas diperiksa:
 *
 *  - >= 90% limit  → warning "Budget [kategori] sudah terpakai 90%+"
 *  - >= 100% limit → danger  "Budget [kategori] sudah terlampaui"
 *
 * Setiap ambang hanya dikirim SEKALI per budget per periode (bulan+tahun).
 * State-nya disimpan sebagai kolom timestamp di tabel budgets (notified_90_at
 * / notified_100_at, migration 2026_09_04_000001) — bukan di memori — sehingga
 * aman terhadap restart worker queue maupun request paralel. Klaim flag
 * dilakukan secara atomik (UPDATE ... WHERE flag IS NULL) sehingga dua save
 * beruntun tidak mungkin sama-sama mengirim notifikasi yang sama.
 */
class BudgetAlertService
{
    /** Ambang peringatan dini: pemakaian >= 90% dari limit. */
    public const WARNING_RATIO = 0.9;

    /**
     * Periksa ulang seluruh budget yang tersentuh oleh expense yang baru
     * disimpan/diubah, lalu kirim notifikasi untuk ambang yang baru tercapai.
     *
     * Metode ini tidak boleh pernah menggagalkan penyimpanan expense itu
     * sendiri (notifikasi hanyalah efek samping), maka semua exception
     * ditangkap dan hanya dicatat ke log.
     */
    public function checkAndNotify(Expense $expense): void
    {
        try {
            $this->evaluateBudgets($expense);
        } catch (\Throwable $e) {
            Log::error('BudgetAlertService gagal memeriksa budget untuk expense '.$expense->id.': '.$e->getMessage());
        }
    }

    private function evaluateBudgets(Expense $expense): void
    {
        // Tanpa pemilik ataupun nominal nyata, tidak ada pemakaian yang bisa
        // dihitung (expense yang parsing-nya gagal total punya amount NULL).
        if (! $expense->user_id || (float) ($expense->amount ?? 0) <= 0) {
            return;
        }

        // Periode budget mengikuti tanggal belanja expense; fallback ke waktu
        // expense dibuat bila tanggal belanja belum terisi.
        $when = $expense->date_shopping
            ? Carbon::parse($expense->date_shopping)
            : Carbon::parse($expense->created_at);
        $month = (int) $when->month;
        $year = (int) $when->year;

        $budgets = Budget::query()
            ->where('user_id', $expense->user_id)
            ->where('month', $month)
            ->where('year', $year)
            ->where(function ($query) use ($expense) {
                if ($expense->category_id !== null) {
                    // Expense berkategori menyentuh budget khusus kategori itu
                    // SEKALIGUS budget umum (category_id NULL) periodenya.
                    $query->where('category_id', $expense->category_id)
                        ->orWhereNull('category_id');
                } else {
                    // Expense tanpa kategori hanya menyentuh budget umum,
                    // karena spentAmount() budget umum memang menjumlahkan
                    // semua expense periode tersebut tanpa melihat kategori.
                    $query->whereNull('category_id');
                }
            })
            // Budget khusus kategori diproses lebih dulu agar urutan notifikasi
            // konsisten (kategori spesifik lebih relevan daripada yang umum).
            ->orderByDesc('category_id')
            ->get();

        foreach ($budgets as $budget) {
            $limit = (float) $budget->amount;
            if ($limit <= 0) {
                continue;
            }

            $spent = $budget->spentAmount();
            $ratio = $spent / $limit;

            // Kedua ambang dicek independen: expense yang langsung melewati
            // 100% akan mengaktifkan kedua flag sekaligus (masing-masing 1x).
            if ($ratio >= 1.0) {
                $this->notifyThresholdOnce($budget, 'notified_100_at', $spent, $month, $year);
            }

            if ($ratio >= self::WARNING_RATIO) {
                $this->notifyThresholdOnce($budget, 'notified_90_at', $spent, $month, $year);
            }
        }
    }

    /**
     * Kirim notifikasi satu ambang batas, tetapi hanya bila flag-nya masih
     * kosong untuk budget & periode ini.
     *
     * Kolom flag diklaim dulu secara atomik (UPDATE ... WHERE flag IS NULL):
     * bila hasilnya 0 baris berarti notifikasi ambang ini sudah pernah
     * dikirim sebelumnya, lalu pengiriman dilewati. Ini mencegah dobel
     * walau dua save terjadi hampir bersamaan.
     */
    private function notifyThresholdOnce(Budget $budget, string $flagColumn, float $spent, int $month, int $year): void
    {
        $claimed = Budget::query()
            ->whereKey($budget->getKey())
            ->whereNull($flagColumn)
            ->update([$flagColumn => now()]);

        if ($claimed === 0) {
            return;
        }

        $user = User::find($budget->user_id);
        if (! $user) {
            Log::warning('Tidak dapat mengirim notifikasi budget (user tidak ditemukan) untuk budget '.$budget->id);

            return;
        }

        $categoryName = $budget->category?->name ?? 'Umum (semua kategori)';
        $limit = (float) $budget->amount;
        $exceeded = $flagColumn === 'notified_100_at';

        $notification = Notification::make();

        if ($exceeded) {
            $notification
                ->danger()
                ->title("Budget {$categoryName} sudah terlampaui")
                ->body('Terpakai '.MoneyFormatter::format($spent).' dari anggaran '.MoneyFormatter::format($limit)." (periode {$month}/{$year}). Mohon sesuaikan pengeluaran atau naikkan anggaran.")
                ->persistent();
        } else {
            $notification
                ->warning()
                ->title("Budget {$categoryName} sudah terpakai 90%+")
                ->body('Terpakai '.MoneyFormatter::format($spent).' dari anggaran '.MoneyFormatter::format($limit)." (periode {$month}/{$year}).");
        }

        $notification->sendToDatabase($user);

        Log::info(sprintf(
            'Notifikasi budget (%s) dikirim ke user %d untuk budget %d: terpakai %.2f dari %.2f (%d/%d).',
            $flagColumn,
            $budget->user_id,
            $budget->id,
            $spent,
            $limit,
            $month,
            $year
        ));
    }
}
