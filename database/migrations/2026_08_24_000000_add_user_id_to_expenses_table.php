<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan kepemilikan per-akun (user_id) pada tabel expenses.
     *
     * Data lama yang belum punya pemilik (user_id NULL) otomatis di-assign ke
     * user pertama di database (biasanya admin/superadmin) supaya tidak hilang.
     * Setelah backfill, kolom dijadikan NOT NULL agar setiap expense wajib
     * milik seorang user — inilah dasar isolasi data multi-user.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
        });

        $hasUsers = DB::table('users')->exists();
        $hasOrphanedExpenses = DB::table('expenses')->whereNull('user_id')->exists();

        if ($hasUsers) {
            $firstUserId = DB::table('users')->orderBy('id')->value('id');

            DB::table('expenses')
                ->whereNull('user_id')
                ->update(['user_id' => $firstUserId]);
        }

        // Hanya jadikan NOT NULL jika semua expense bisa dijamin punya pemilik.
        // Kasus ekstrem: ada expense tapi belum ada user sama sekali → pertahankan
        // nullable supaya migration tidak gagal dan data tidak terkurung.
        if (! $hasOrphanedExpenses || $hasUsers) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
