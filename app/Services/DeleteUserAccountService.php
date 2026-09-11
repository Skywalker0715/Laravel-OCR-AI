<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus satu akun user BESERTA seluruh datanya secara permanen:
 * expense items, expenses (+ foto struk fisik di disk 'receipts'),
 * budgets, kategori PRIBADI (kategori default sistem user_id NULL
 * dipakai bersama dan tidak disentuh), notifikasi database, lalu
 * record user itu sendiri.
 *
 * Semua dijalankan dalam satu database transaction: jika ada langkah
 * yang gagal di tengah, seluruh penghapusan di-rollback dan tidak ada
 * data yang setengah terhapus.
 */
class DeleteUserAccountService
{
    /**
     * Hapus akun $user beserta seluruh data terkaitnya.
     *
     * Query memakai withoutGlobalScopes() + filter user_id eksplisit agar
     * tidak bergantung pada siapa yang sedang login saat service dipanggil
     * (global scope OwnedByUserScope hanya aktif ketika ada sesi login).
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // 1 & 2. Semua expense item + expense milik user. Expense dihapus
            // SATU PER SATU (bukan mass delete) agar model event
            // Expense::deleted ikut tereksekusi dan membersihkan file foto
            // struk fisik di disk privat 'receipts' (reuse logic yang sudah ada).
            $userExpenses = Expense::query()
                ->withoutGlobalScopes()
                ->where('user_id', $user->getKey())
                ->get();

            foreach ($userExpenses as $expense) {
                // Items dihapus eksplisit per permintaan spesifikasi, meski
                // FK expense_items.expenses_id sudah cascadeOnDelete.
                ExpenseItem::query()
                    ->where('expenses_id', $expense->getKey())
                    ->delete();

                $expense->delete();
            }

            // 3. Semua budget milik user.
            Budget::query()
                ->withoutGlobalScopes()
                ->where('user_id', $user->getKey())
                ->delete();

            // 4. HANYA kategori pribadi milik user. Kategori default sistem
            // (user_id NULL) dipakai bersama semua user — jangan disentuh.
            Category::query()
                ->where('user_id', $user->getKey())
                ->delete();

            // 5. Semua notifikasi database milik user (lonceng Filament).
            $user->notifications()->delete();

            // 6. Terakhir, hapus record user itu sendiri.
            $user->delete();
        });
    }
}
