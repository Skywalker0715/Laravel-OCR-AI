<?php

namespace App\Console\Commands;

use App\Jobs\AIParserJob;
use App\Models\Expense;
use Illuminate\Console\Command;

/**
 * Proses ulang parsing dari `note` (atau OCR ulang dari foto bila note kosong)
 * untuk expense yang masih kosong / gagal dicatat.
 */
class ReprocessExpense extends Command
{
    protected $signature = 'expenses:reprocess {id? : ID expense tertentu (opsional)}
                            {--all : Proses ulang SEMUA expense, bukan hanya yang kosong}';

    protected $description = 'Proses ulang parsing OCR & AI untuk expense yang masih kosong/gagal';

    public function handle(): int
    {
        $target = $this->resolveTargetExpenses();

        if ($target->isEmpty()) {
            $this->warn('Tidak ada expense untuk diproses ulang.');

            return self::SUCCESS;
        }

        $success = 0;
        $failed = 0;

        foreach ($target as $expense) {
            $this->line("  → Proses ulang expense #{$expense->id}: {$expense->title} ...");

            $result = (new AIParserJob($expense))->reprocess($expense);

            if ($result['ok']) {
                $success++;
                $this->info('    ✔ '.$result['note']);
            } else {
                $failed++;
                $this->error('    ✘ '.$result['note']);
            }
        }

        $this->newLine();
        $this->info("Selesai. {$success} berhasil, {$failed} gagal total.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Pilih expense yang harus di-proses ulang berdasarkan argumen/opsi.
     *
     * @return \Illuminate\Support\Collection<int, Expense>
     */
    private function resolveTargetExpenses(): \Illuminate\Support\Collection
    {
        // Argumen id spesifik.
        if ($id = $this->argument('id')) {
            $expense = Expense::with('items')->find((int) $id);
            if (! $expense) {
                $this->error("Expense dengan id {$id} tidak ditemukan.");
                exit(self::FAILURE);
            }

            return collect([$expense]);
        }

        // Default: hanya expense yang parsingnya belum terisi nominal.
        $query = Expense::with('items')->where(function ($q): void {
            $q->whereNull('amount')->orWhere('amount', 0);
        });

        // --all: proses semua tanpa filter.
        if ($this->option('all')) {
            $query = Expense::with('items');
        }

        return $query->get();
    }
}