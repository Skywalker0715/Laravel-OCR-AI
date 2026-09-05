<?php

namespace App\Filament\Resources\Expenses\Pages\Concerns;

use App\Models\Expense;

/**
 * Livewire polling untuk halaman Filament yang menampilkan hasil parsing
 * struk (OCR + AI).
 *
 * Parsing berjalan ASYNC di queue (AIParserJob): setelah user mengunggah foto
 * struk baru (Create) atau mengganti fotonya (Edit), field hasil parsing
 * (vendor, amount, item, dst.) baru terisi beberapa detik kemudian oleh queue
 * worker. Tanpa polling, user harus me-refresh halaman secara manual untuk
 * melihat hasilnya.
 *
 * Trait ini menyediakan:
 *  - checkParsingStatus(): target `wire:poll.3s` — dicek tiap 3 detik; bila
 *    hasil parsing sudah terisi, polling dihentikan dan data terbaru
 *    ditampilkan otomatis (tanpa refresh manual).
 *  - Batas attempt polling agar halaman tidak membebani server selamanya
 *    bila parsing memang gagal permanen.
 *
 * Cara memakai pada sebuah page class:
 *  1. `use PollsParsingStatus;`
 *  2. Implementasikan hasPendingParsingResults() dan onParsingResultsReady().
 *  3. Set `$this->isWaitingForParsing = $this->hasPendingParsingResults();`
 *     di akhir mount().
 *  4. Render view 'filament.expenses.parsing-status-badge' (mis. lewat
 *     override content()) — badge membawa wire:poll dan hanya tampil selama
 *     $isWaitingForParsing bernilai true, sehingga polling berhenti otomatis
 *     begitu badge menghilang.
 */
trait PollsParsingStatus
{
    /**
     * Batas attempt polling (3 detik per attempt) sebelum polling berhenti
     * diam-diam. 100 attempt ≈ 5 menit, selaras dengan ambang job "stale" pada
     * widget PendingParsingJobsAlert — parsing yang melewati batas ini dianggap
     * gagal permanen (pemilik expense sudah menerima notifikasi gagal dari
     * AIParserJob) dan tidak perlu dipoll terus-menerus.
     */
    protected const MAX_PARSING_POLL_ATTEMPTS = 100;

    /** True = badge "Sedang diproses..." tampil dan wire:poll aktif. */
    public bool $isWaitingForParsing = false;

    /** Jumlah attempt polling yang sudah berjalan (dipakai untuk batas maksimum). */
    public int $parsingPollAttempts = 0;

    /**
     * Target `wire:poll.3s` pada badge "Sedang diproses...". Setiap 3 detik
     * cek apakah field hasil parsing (vendor/amount) sudah terisi — menandakan
     * job selesai diproses. Bila sudah: hentikan polling dan tampilkan data
     * terbaru tanpa perlu refresh manual dari user.
     */
    public function checkParsingStatus(): void
    {
        if (! $this->isWaitingForParsing) {
            return;
        }

        $this->parsingPollAttempts++;

        if (! $this->hasPendingParsingResults()) {
            $this->isWaitingForParsing = false;
            $this->onParsingResultsReady();

            return;
        }

        if ($this->parsingPollAttempts >= self::MAX_PARSING_POLL_ATTEMPTS) {
            // Parsing tampaknya gagal permanen — berhenti agar tidak membebani
            // server dengan polling tanpa batas.
            $this->isWaitingForParsing = false;
        }
    }

    /**
     * True bila masih ada hasil parsing yang belum terisi (field vendor/amount
     * masih kosong) untuk konteks halaman ini.
     */
    abstract protected function hasPendingParsingResults(): bool;

    /**
     * Dipanggil tepat ketika hasil parsing terdeteksi sudah tersedia — di sini
     * halaman harus menampilkan data terbarunya (refresh tabel / record).
     */
    abstract protected function onParsingResultsReady(): void;

    /**
     * True bila expense ini masih menunggu hasil parsing: field vendor DAN
     * amount sama-sama kosong. Job selesai ditandai dengan terisinya minimal
     * salah satu dari keduanya (lihat AIParserJob::reprocess).
     */
    protected static function isExpenseParsingPending(Expense $expense): bool
    {
        return blank($expense->vendor)
            && (blank($expense->amount) || (float) $expense->amount <= 0);
    }
}
