<?php

namespace App\Console\Commands;

use App\Models\Expense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pindahkan foto struk dari disk public ke disk privat 'receipts' (audit keamanan #2).
 * Idempotent; tiap file di-copy → verifikasi ukuran → baru dihapus agar data lama aman.
 */
class MoveReceiptsToPrivateDisk extends Command
{
    protected $signature = 'receipts:move-to-private-disk {--dry-run : Tampilkan rencana pemindahan tanpa memindahkan apapun}';

    protected $description = 'Pindahkan foto struk dari disk public ke disk privat receipts (temuan audit #2)';

    public function handle(): int
    {
        $old = Storage::disk('public');
        $new = Storage::disk('receipts');
        $dryRun = (bool) $this->option('dry-run');

        // Kolom receipt_image adalah path relatif disk yang sama di kedua
        // disk (root baru = storage/app/private), sehingga nilainya tidak
        // perlu diubah di database — cukup filenya yang dipindah.
        $paths = Expense::query()
            ->whereNotNull('receipt_image')
            ->where('receipt_image', '!=', '')
            ->pluck('receipt_image', 'id');

        if ($paths->isEmpty()) {
            $this->info('Tidak ada expense dengan foto struk — tidak ada yang perlu dipindah.');

            return self::SUCCESS;
        }

        $moved = 0;
        $already = 0;
        $missing = 0;
        $failed = 0;

        foreach ($paths as $expenseId => $path) {
            if ($new->exists($path)) {
                $already++;

                continue;
            }

            if (! $old->exists($path)) {
                $missing++;
                $this->warn("  [HILANG] expense #{$expenseId}: '{$path}' tidak ditemukan di kedua disk.");

                continue;
            }

            if ($dryRun) {
                $moved++;
                $this->line("  [AKAN DIPINDAH] expense #{$expenseId}: {$path}");

                continue;
            }

            try {
                // 1) Salin via stream (hemat memori untuk file besar).
                $stream = $old->readStream($path);

                try {
                    $new->writeStream($path, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                // 2) Verifikasi salinan utuh SEBELUM menyentuh file lama.
                if (! $new->exists($path) || $new->size($path) !== $old->size($path)) {
                    $new->delete($path); // buang salinan yang cacat

                    throw new \RuntimeException('Ukuran file tidak cocok setelah disalin ke disk baru.');
                }

                // 3) Baru hapus file lama yang sudah terverifikasi tersalin.
                $old->delete($path);

                $moved++;
                $this->line("  [PINDAH] expense #{$expenseId}: {$path}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("  [GAGAL] expense #{$expenseId}: {$path} — {$e->getMessage()}");
            }
        }

        $this->info("Selesai. Dipindah: {$moved}, sudah ada di disk baru: {$already}, tidak ditemukan: {$missing}, gagal: {$failed}.");

        // File lama yatim (tidak ter-referensi database) cukup dilaporkan —
        // tidak dihapus otomatis demi keamanan data.
        $orphans = collect($old->files('receipts'))->diff($paths->values())->count();

        if ($orphans > 0) {
            $this->warn("  Ada {$orphans} file yatim di disk lama (storage/app/public/receipts) yang tidak ter-referensi database — dibiarkan apa adanya, pertimbangkan dihapus manual.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}