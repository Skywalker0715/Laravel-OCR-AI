<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Expense;
use Illuminate\Console\Command;

/**
 * Back-fill kategori "Lainnya" untuk expense lama yang category_id-nya NULL,
 * tanpa menyentuh expense yang sudah punya kategori.
 */
class AssignDefaultCategory extends Command
{
    protected $signature = 'expenses:assign-default-category';

    protected $description = 'Set kategori default "Lainnya" untuk expense yang masih tanpa kategori';

    public function handle(): int
    {
        $fallback = Category::fallbackCategory();

        if (! $fallback) {
            $this->error('Kategori "Lainnya" tidak ditemukan dan tidak bisa dibuat. Jalankan `php artisan db:seed --class=CategorySeeder`.');

            return self::FAILURE;
        }

        $count = 0;

        // chunkById aman untuk data besar; di konsol tidak ada Auth sehingga
        // scope OwnedByUserScope tidak aktif (scope hanya menyala saat
        // Auth::check()) — SEMUA expense tersapu.
        Expense::query()
            ->whereNull('category_id')
            ->chunkById(500, function ($expenses) use ($fallback, &$count): void {
                foreach ($expenses as $expense) {
                    $expense->category_id = $fallback->id;
                    $expense->save();
                    $count++;
                }
            });

        $this->info("Selesai. {$count} expense tanpa kategori kini memakai kategori '{$fallback->name}'. Semua periode waktu tercakup (bukan hanya bulan berjalan) agar data lama ikut terkelompok di grafik.");

        return self::SUCCESS;
    }
}